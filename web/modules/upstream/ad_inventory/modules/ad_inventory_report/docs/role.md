# The Marketeer overwatch role

Someone who watches the advertising but changes nothing: a marketer, an
account manager, the owner checking how a campaign runs.

| Item | Value |
|---|---|
| Machine name | `ad_inventory_overwatch` |
| Label | Marketeer overwatch |
| Shipped in | `config/install`, with an enforced dependency on the submodule (removed on uninstall) |
| Grants | `view ad inventory report`, `export ad inventory report`, `view the administration theme`, and `access toolbar` where the toolbar module is installed |
| Never has | anything that creates, edits, approves or stops a slot, format, provider, creative or booking; `bypass advertising`; user administration |

`access toolbar` belongs to the toolbar module, which a site may not run, so
it is granted when the submodule is installed with toolbar on, or when toolbar
is installed later, instead of being written in `config/install`.

## Granting it

- In the admin: **People**, edit the account, tick *Marketeer overwatch*.
- With Drush: `drush user:role:add ad_inventory_overwatch <name>`.
- Seeded: a site's account seed may add the role; the role itself is config.

The account needs nothing else. It signs in, sees **Ad inventory** in the
toolbar, and reads every tab and CSV. Every edit, approve and stop page
answers 403 to it; the Health tab still links to them, so it can tell someone
who can act.

## Who else sees the dashboard

Any role given *View the ad inventory report* (administrators have every
permission). *Export the ad inventory report* adds the CSV links.

## Do not

- Do not add editing permissions to this role: make another role. The role is
  config the module owns; a change to it in `config/sync` is kept, but its
  meaning ("watches, changes nothing") is what the documentation promises.
- Do not give it *Bypass advertising*: the overwatch should see the site as
  readers do.
