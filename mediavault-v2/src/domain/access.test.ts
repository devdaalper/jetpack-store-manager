/**
 * MediaVault v2 — Access Control Tests
 *
 * Each test case maps to a row in the authorization truth table.
 * These tests ARE the authorization spec.
 */

import { describe, it, expect } from "vitest";
import {
  canAccessFolder,
  canDownload,
  canPlay,
  getRemainingPlays,
  resolveTierFromSales,
  resolveAllowedTiers,
  getMediaKind,
  getFileExtension,
} from "./access";

// ─── canAccessFolder ────────────────────────────────────────────────

describe("canAccessFolder", () => {
  // Truth table: userTier × allowedTiers → result
  const cases: Array<[number, number[], boolean, string]> = [
    // Tier 5 (FULL) always passes
    [5, [1, 3], true, "tier 5 bypasses any restriction"],
    [5, [], true, "tier 5 with empty permissions"],
    [5, [0], true, "tier 5 even when only demo allowed"],

    // Empty permissions = open to all
    [0, [], true, "demo with no restrictions"],
    [1, [], true, "basic with no restrictions"],
    [3, [], true, "vip_videos with no restrictions"],

    // Specific tier in allowed list
    [1, [1, 3, 5], true, "basic in allowed list"],
    [3, [1, 3, 5], true, "vip_videos in allowed list"],
    [0, [0, 1], true, "demo explicitly allowed"],

    // Tier NOT in allowed list
    [0, [1, 3, 5], false, "demo not in allowed list"],
    [1, [3, 5], false, "basic not in allowed list"],
    [2, [1, 3], false, "vip_basic not in allowed list"],
    [4, [1, 2, 3], false, "vip_pelis not in allowed list"],
  ];

  it.each(cases)(
    "tier %d with allowed %j → %s (%s)",
    (tier, allowed, expected) => {
      expect(canAccessFolder(tier, allowed)).toBe(expected);
    },
  );
});

// ─── resolveAllowedTiers ────────────────────────────────────────────

describe("resolveAllowedTiers", () => {
  const permissions: Record<string, number[]> = {
    "Music/": [1, 3, 5],
    "Videos/Premium/": [3, 4, 5],
    "Public/": [],
  };

  it("returns exact match for defined folder", () => {
    expect(resolveAllowedTiers("Music/", permissions)).toEqual([1, 3, 5]);
  });

  it("inherits parent permissions for subfolder", () => {
    expect(resolveAllowedTiers("Music/Rock/", permissions)).toEqual([1, 3, 5]);
  });

  it("inherits deeply nested folders", () => {
    expect(resolveAllowedTiers("Music/Rock/Classic/", permissions)).toEqual([1, 3, 5]);
  });

  it("returns exact match for nested defined folder", () => {
    expect(resolveAllowedTiers("Videos/Premium/", permissions)).toEqual([3, 4, 5]);
  });

  it("returns empty array (open) for undefined folder", () => {
    expect(resolveAllowedTiers("Unknown/", permissions)).toEqual([]);
  });

  it("returns empty array for Public folder", () => {
    expect(resolveAllowedTiers("Public/", permissions)).toEqual([]);
  });

  it("normalizes path without trailing slash", () => {
    expect(resolveAllowedTiers("Music", permissions)).toEqual([1, 3, 5]);
  });
});

// ─── canDownload ────────────────────────────────────────────────────

describe("canDownload", () => {
  const cases: Array<[number, number[], boolean, string]> = [
    // Demo can NEVER download
    [0, [], false, "demo cannot download even with open folder"],
    [0, [0, 1], false, "demo cannot download even when explicitly allowed"],

    // Paid users can download if they have folder access
    [1, [1, 3], true, "basic with folder access"],
    [3, [1, 3], true, "vip_videos with folder access"],
    [5, [1, 3], true, "full always can download"],

    // Paid users blocked by folder restrictions
    [1, [3, 5], false, "basic without folder access"],
    [2, [1, 3], false, "vip_basic without folder access"],

    // Open folder
    [1, [], true, "basic with open folder"],
    [4, [], true, "vip_pelis with open folder"],
  ];

  it.each(cases)(
    "tier %d with allowed %j → %s (%s)",
    (tier, allowed, expected) => {
      expect(canDownload(tier, allowed)).toBe(expected);
    },
  );
});

// ─── canPlay ────────────────────────────────────────────────────────

describe("canPlay", () => {
  const cases: Array<[number, number, boolean, string]> = [
    // Paid users: always can play
    [1, 0, true, "basic with 0 plays"],
    [1, 999, true, "basic with 999 plays (unlimited)"],
    [5, 0, true, "full with 0 plays"],

    // Demo: limited to 15 plays
    [0, 0, true, "demo with 0 plays"],
    [0, 7, true, "demo with 7 plays"],
    [0, 14, true, "demo with 14 plays (1 remaining)"],
    [0, 15, false, "demo at limit (15 plays)"],
    [0, 20, false, "demo over limit"],
  ];

  it.each(cases)(
    "tier %d with %d plays → %s (%s)",
    (tier, plays, expected) => {
      expect(canPlay(tier, plays)).toBe(expected);
    },
  );
});

// ─── getRemainingPlays ──────────────────────────────────────────────

describe("getRemainingPlays", () => {
  it("returns -1 for paid users (unlimited)", () => {
    expect(getRemainingPlays(1, 0)).toBe(-1);
    expect(getRemainingPlays(5, 100)).toBe(-1);
  });

  it("returns correct remaining for demo users", () => {
    expect(getRemainingPlays(0, 0)).toBe(15);
    expect(getRemainingPlays(0, 7)).toBe(8);
    expect(getRemainingPlays(0, 14)).toBe(1);
    expect(getRemainingPlays(0, 15)).toBe(0);
  });

  it("never returns negative", () => {
    expect(getRemainingPlays(0, 20)).toBe(0);
    expect(getRemainingPlays(0, 100)).toBe(0);
  });
});

// ─── resolveTierFromSales ───────────────────────────────────────────

describe("resolveTierFromSales", () => {
  it("returns 0 for no sales", () => {
    expect(resolveTierFromSales([])).toBe(0);
  });

  it("returns 0 for only failed sales", () => {
    expect(
      resolveTierFromSales([{ package: "full", status: "Falló" }]),
    ).toBe(0);
  });

  it("maps exact package names to tiers", () => {
    expect(
      resolveTierFromSales([{ package: "basic", status: "Completado" }]),
    ).toBe(1);
    expect(
      resolveTierFromSales([{ package: "vip_basic", status: "Completado" }]),
    ).toBe(2);
    expect(
      resolveTierFromSales([{ package: "vip_videos", status: "Completado" }]),
    ).toBe(3);
    expect(
      resolveTierFromSales([{ package: "vip_pelis", status: "Completado" }]),
    ).toBe(4);
    expect(
      resolveTierFromSales([{ package: "full", status: "Completado" }]),
    ).toBe(5);
  });

  it("takes the highest tier from multiple sales", () => {
    expect(
      resolveTierFromSales([
        { package: "basic", status: "Completado" },
        { package: "vip_videos", status: "Completado" },
      ]),
    ).toBe(3);
  });

  it("ignores failed sales when calculating max tier", () => {
    expect(
      resolveTierFromSales([
        { package: "full", status: "Falló" },
        { package: "basic", status: "Completado" },
      ]),
    ).toBe(1);
  });

  it("handles fuzzy/legacy package names", () => {
    expect(
      resolveTierFromSales([{ package: "Full Access Pack", status: "Completado" }]),
    ).toBe(5);
    expect(
      resolveTierFromSales([{ package: "VIP + Videos Musicales", status: "Completado" }]),
    ).toBe(3);
    expect(
      resolveTierFromSales([{ package: "Paquete Básico", status: "Completado" }]),
    ).toBe(1);
  });
});

// ─── getMediaKind ───────────────────────────────────────────────────

describe("getMediaKind", () => {
  it("identifies audio extensions", () => {
    expect(getMediaKind("mp3")).toBe("audio");
    expect(getMediaKind("wav")).toBe("audio");
    expect(getMediaKind("flac")).toBe("audio");
    expect(getMediaKind("m4a")).toBe("audio");
    expect(getMediaKind("MP3")).toBe("audio"); // case insensitive
  });

  it("identifies video extensions", () => {
    expect(getMediaKind("mp4")).toBe("video");
    expect(getMediaKind("mov")).toBe("video");
    expect(getMediaKind("mkv")).toBe("video");
    expect(getMediaKind("MP4")).toBe("video");
  });

  it("returns 'other' for unknown extensions", () => {
    expect(getMediaKind("pdf")).toBe("other");
    expect(getMediaKind("zip")).toBe("other");
    expect(getMediaKind("txt")).toBe("other");
    expect(getMediaKind("")).toBe("other");
  });

  it("handles extension with leading dot", () => {
    expect(getMediaKind(".mp3")).toBe("audio");
    expect(getMediaKind(".mp4")).toBe("video");
  });
});

// ─── getFileExtension ───────────────────────────────────────────────

describe("getFileExtension", () => {
  it("extracts extension from filename", () => {
    expect(getFileExtension("song.mp3")).toBe("mp3");
    expect(getFileExtension("video.final.mp4")).toBe("mp4");
  });

  it("returns lowercase", () => {
    expect(getFileExtension("Song.MP3")).toBe("mp3");
  });

  it("returns empty string for no extension", () => {
    expect(getFileExtension("README")).toBe("");
    expect(getFileExtension("")).toBe("");
  });

  it("returns empty string for trailing dot", () => {
    expect(getFileExtension("file.")).toBe("");
  });
});
