<?php

declare(strict_types=1);

namespace Drupal\ga4_report\Drush\Commands;

use Drupal\ga4_report\Refresher;
use Drupal\ga4_report\ReportException;
use Drupal\ga4_report\SnapshotStore;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Refresh the report's sources, check them, and mint a Google consent.
 */
final class Ga4ReportCommands extends DrushCommands {
  use AutowireTrait;

  /**
   * Scopes a consent can ask for, by short name.
   */
  private const SCOPES = [
    'adsense' => 'https://www.googleapis.com/auth/adsense.readonly',
    'analytics' => 'https://www.googleapis.com/auth/analytics.readonly',
  ];

  public function __construct(
    private readonly Refresher $refresher,
    private readonly SnapshotStore $store,
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Fetches every configured source now (or one, with --source).
   *
   * Exits non-zero when any source failed, so a system cron job can alert;
   * each failed source keeps its previous snapshot.
   */
  #[Command(name: 'ga4-report:refresh', aliases: ['ga4r'])]
  #[Option(name: 'source', description: 'Refresh one source only: ga4, cloudflare or adsense.')]
  public function refresh(array $options = ['source' => NULL]): int {
    try {
      $outcomes = $this->refresher->refresh($options['source'] ?: NULL);
    }
    catch (ReportException $e) {
      $this->logger()?->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $ok = TRUE;
    foreach ($outcomes as $outcome) {
      $this->output()->writeln($outcome['message']);
      $ok = $ok && $outcome['ok'];
    }
    return $ok ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Says, per source, whether it is configured, its snapshot and last error.
   */
  #[Command(name: 'ga4-report:status')]
  public function status(): void {
    $configured = $this->refresher->configured();
    $out = $this->output();
    foreach ($this->refresher->sources() as $id => $source) {
      $meta = $this->store->meta($id);
      $out->writeln(sprintf('%s (%s)', $source->label(), $id));
      $out->writeln(sprintf('  configured  %s', isset($configured[$id]) ? 'yes' : 'no'));
      $out->writeln(sprintf('  snapshot    %s', empty($meta['fetched']) || !$this->store->snapshot($id) ? 'none' : date('Y-m-d H:i T', (int) $meta['fetched'])));
      $out->writeln(sprintf('  last try    %s', empty($meta['attempted']) ? 'never' : date('Y-m-d H:i T', (int) $meta['attempted'])));
      $out->writeln(sprintf('  last error  %s', $meta['error'] ?? 'none'));
    }
    $out->writeln(sprintf('cron due      %s', $this->refresher->isDue() ? 'yes' : 'no'));
  }

  /**
   * Mints an authorized-user credentials file through Google's consent screen.
   *
   * For AdSense, which reads as a person and not as a service account. Needs
   * an OAuth client of type "Desktop app" from a Google Cloud project whose
   * consent screen is published ("In production"): a client left in Testing
   * mints refresh tokens that die after seven days.
   *
   * Prints a consent address. Open it in the browser signed in as the
   * account's owner and approve; the browser then fails to load a
   * 127.0.0.1 address, which is expected: copy that whole address and paste
   * it here. The file is written with mode 0600. No token is printed.
   */
  #[Command(name: 'ga4-report:google-consent')]
  #[Option(name: 'client', description: 'Path to the OAuth client JSON ("Desktop app") downloaded from Google Cloud.')]
  #[Option(name: 'scope', description: 'adsense (default) or analytics.')]
  #[Option(name: 'out', description: 'Where to write the authorized-user file, e.g. secrets/adsense.json.')]
  #[Option(name: 'port', description: 'The loopback port in the redirect address (nothing needs to listen on it).')]
  public function consent(array $options = ['client' => '', 'scope' => 'adsense', 'out' => '', 'port' => '8765']): int {
    $scope = self::SCOPES[$options['scope']] ?? NULL;
    if ($scope === NULL || $options['client'] === '' || $options['out'] === '') {
      $this->logger()?->error('Give --client=<OAuth client JSON> and --out=<file>; --scope is adsense or analytics.');
      return self::EXIT_FAILURE;
    }
    $client = json_decode((string) @file_get_contents($options['client']), TRUE)['installed'] ?? NULL;
    if (!is_array($client) || empty($client['client_id']) || empty($client['client_secret'])) {
      $this->logger()?->error(sprintf('%s is not an OAuth client of type "Desktop app" (its JSON has no "installed" section).', $options['client']));
      return self::EXIT_FAILURE;
    }
    $redirect = 'http://127.0.0.1:' . (int) $options['port'];
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $state = bin2hex(random_bytes(12));
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
      'client_id' => $client['client_id'],
      'redirect_uri' => $redirect,
      'response_type' => 'code',
      'scope' => $scope,
      'access_type' => 'offline',
      'prompt' => 'consent',
      'state' => $state,
      'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '='),
      'code_challenge_method' => 'S256',
    ]);
    $this->output()->writeln("Open this address in the browser signed in as the account's owner, and approve:\n\n$url\n");
    $this->output()->writeln("The browser then fails to load $redirect/... — that is expected. Copy that whole address.");
    $pasted = (string) $this->io()->ask('Paste the address here');
    parse_str((string) parse_url(trim($pasted), PHP_URL_QUERY), $answer);
    if (($answer['state'] ?? '') !== $state || empty($answer['code'])) {
      $this->logger()?->error(isset($answer['error']) ? 'Google answered: ' . $answer['error'] : 'That address does not carry this consent\'s code.');
      return self::EXIT_FAILURE;
    }
    try {
      $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
        'form_params' => [
          'code' => $answer['code'],
          'client_id' => $client['client_id'],
          'client_secret' => $client['client_secret'],
          'redirect_uri' => $redirect,
          'grant_type' => 'authorization_code',
          'code_verifier' => $verifier,
        ],
        'timeout' => 30,
      ]);
      $token = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->logger()?->error('Google refused the code: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }
    if (empty($token['refresh_token'])) {
      $this->logger()?->error('Google returned no refresh token. Remove the app from the account\'s third-party access and consent again.');
      return self::EXIT_FAILURE;
    }
    if (is_file($options['out'])) {
      rename($options['out'], $options['out'] . '.previous-' . date('Ymd-His'));
    }
    $old = umask(0077);
    file_put_contents($options['out'], json_encode([
      'type' => 'authorized_user',
      'client_id' => $client['client_id'],
      'client_secret' => $client['client_secret'],
      'refresh_token' => $token['refresh_token'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    umask($old);
    chmod($options['out'], 0600);
    $this->output()->writeln(sprintf('Wrote %s (mode 0600).', $options['out']));
    $this->listAccounts((string) $options['scope'], (string) ($token['access_token'] ?? ''));
    return self::EXIT_SUCCESS;
  }

  /**
   * Lists what the new credential can see, so the site's settings can name it.
   */
  private function listAccounts(string $scope, string $accessToken): void {
    $url = $scope === 'adsense'
      ? 'https://adsense.googleapis.com/v2/accounts'
      : 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        'timeout' => 30,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->output()->writeln('The credential works, but listing its accounts failed: ' . $e->getMessage());
      return;
    }
    if ($scope === 'adsense') {
      foreach ($data['accounts'] ?? [] as $account) {
        $this->output()->writeln(sprintf('AdSense account %s (%s)', $account['name'] ?? '?', $account['displayName'] ?? ''));
      }
      return;
    }
    foreach ($data['accountSummaries'] ?? [] as $summary) {
      foreach ($summary['propertySummaries'] ?? [] as $property) {
        $this->output()->writeln(sprintf('GA4 %s (%s)', $property['property'] ?? '?', $property['displayName'] ?? ''));
      }
    }
  }

}
