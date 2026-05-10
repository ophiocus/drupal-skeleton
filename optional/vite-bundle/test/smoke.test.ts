// Vitest smoke — proves the test runner is wired up.
// Replace with real tests as the theme bundle grows.

import { describe, it, expect } from "vitest";

describe("vitest is wired", () => {
  it("runs a trivial assertion", () => {
    expect(2 + 2).toBe(4);
  });

  it("supports async tests", async () => {
    const result = await Promise.resolve("ok");
    expect(result).toBe("ok");
  });
});
