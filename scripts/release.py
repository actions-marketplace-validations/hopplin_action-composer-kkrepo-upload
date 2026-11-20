#!/usr/bin/env python3
"""Publish a verified stable release and maintain its major-version alias."""

import json
import os
import re
import subprocess
import sys


def stable_version(tag):
    match = re.fullmatch(r"v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", tag)
    return tuple(map(int, match.groups())) if match else None


def command(*args):
    return subprocess.check_output(args, text=True).strip()


def main():
    tag = os.environ.get("RELEASE_TAG", "")
    version = stable_version(tag)
    if version is None:
        raise RuntimeError("Use a stable release tag such as v1.0.0; prerelease/build suffixes and leading zeros are not supported.")
    repo = os.environ.get("GH_REPO", "")
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
        raise RuntimeError("GH_REPO must be OWNER/REPOSITORY.")
    commit = command("git", "rev-parse", "--verify", f"refs/tags/{tag}^{{commit}}")
    expected = os.environ.get("RELEASE_SHA", "")
    if not expected or commit != expected:
        raise RuntimeError("Release tag does not point to the commit verified by this workflow.")

    # The pushed tag must also exist remotely and must not have moved since verification.
    subprocess.run(["git", "fetch", "--no-tags", "origin", f"refs/tags/{tag}"], check=True)
    if command("git", "rev-parse", "FETCH_HEAD^{commit}") != commit:
        raise RuntimeError("Remote release tag changed after verification; refusing to publish.")

    pages = json.loads(command("gh", "api", "--paginate", "--slurp", f"repos/{repo}/releases?per_page=100"))
    releases = [release for page in pages for release in page]
    alias = f"v{version[0]}"
    if any(release["tag_name"] == alias for release in releases):
        raise RuntimeError(f"{alias} has a GitHub release attached. Reserve releases for full versions; the major tag must remain movable.")
    existing = next((r for r in releases if r["tag_name"] == tag), None)
    if existing and (existing.get("draft") or existing.get("prerelease")):
        raise RuntimeError("An existing draft/prerelease uses this stable tag. Resolve that release before rerunning.")

    stable = [r for r in releases if not r.get("draft") and not r.get("prerelease") and stable_version(r["tag_name"]) is not None]
    latest = max([version] + [stable_version(r["tag_name"]) for r in stable]) == version
    if existing is None:
        subprocess.run([
            "gh", "release", "create", tag, "--repo", repo, "--verify-tag",
            "--generate-notes", "--title", tag, f"--latest={str(latest).lower()}",
        ], check=True)
        stable.append({"tag_name": tag})
        print(f"Published {tag}.", flush=True)
    else:
        print(f"Release {tag} already exists; continuing without creating a duplicate.", flush=True)

    # Use published releases, not arbitrary pushed tags: unverified future tags must not win.
    same_major = [r["tag_name"] for r in stable if stable_version(r["tag_name"])[0] == version[0]]
    target_tag = max(same_major, key=stable_version)
    subprocess.run(["git", "fetch", "--no-tags", "origin", f"refs/tags/{target_tag}"], check=True)
    target_commit = command("git", "rev-parse", "FETCH_HEAD^{commit}")
    current = command("git", "ls-remote", "--refs", "origin", f"refs/tags/{alias}")
    old_sha = current.split()[0] if current else ""
    if old_sha != target_commit:
        # Do not overwrite a concurrent human update to the major tag.
        subprocess.run([
            "git", "push", f"--force-with-lease=refs/tags/{alias}:{old_sha}",
            "origin", f"{target_commit}:refs/tags/{alias}",
        ], check=True)
    print(f"{alias} points to {target_tag} ({target_commit}).", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError, ValueError, KeyError) as error:
        print(f"Release failed: {error}", file=sys.stderr)
        sys.exit(1)
