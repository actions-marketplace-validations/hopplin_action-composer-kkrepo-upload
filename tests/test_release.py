#!/usr/bin/env python3
"""Exercise releases with real local Git tags and a fake gh CLI; never publish."""

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/release.py"
spec = importlib.util.spec_from_file_location("action_release", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

FAKE_GH = r'''#!/usr/bin/env python3
import json, os, pathlib, sys
state = pathlib.Path(os.environ["FAKE_GH_STATE"])
data = json.loads(state.read_text())
args = sys.argv[1:]
data["calls"].append(args)
if args[0] == "api":
    print(json.dumps([data["releases"]]))
elif args[:2] == ["release", "create"]:
    assert "--verify-tag" in args and "--generate-notes" in args
    data["releases"].append({"tag_name": args[2], "draft": False, "prerelease": False})
    print("Created simulated release " + args[2])
else:
    raise SystemExit("Unexpected gh invocation: " + repr(args))
state.write_text(json.dumps(data))
'''


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="kkrepo-release-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.remote = self.root / "remote.git"
        self.work = self.root / "work"
        self.work.mkdir()
        self.git("init", "--bare", str(self.remote), cwd=self.root)
        self.git("init", "-b", "main")
        self.git("config", "user.name", "Release fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "commit.gpgsign", "false")
        self.git("config", "tag.gpgsign", "false")
        self.git("remote", "add", "origin", str(self.remote))
        self.commits = {}
        for tag in ["v1.0.0", "v1.1.0", "v1.2.0", "v2.0.0"]:
            self.git("commit", "--allow-empty", "-m", tag)
            self.commits[tag] = self.git("rev-parse", "HEAD")
            if tag == "v1.0.0":
                self.git("tag", "-a", tag, "-m", "Annotated release tag")
            else:
                self.git("tag", tag)
        self.git("push", "origin", "main", "--tags")
        self.state = self.root / "gh.json"
        self.state.write_text(json.dumps({"releases": [], "calls": []}))
        binary = self.root / "bin"
        binary.mkdir()
        (binary / "gh").write_text(FAKE_GH)
        (binary / "gh").chmod(0o755)
        self.env = {**os.environ, "PATH": str(binary) + os.pathsep + os.environ["PATH"],
                    "FAKE_GH_STATE": str(self.state), "GH_REPO": "fixture/action"}

    def git(self, *args, cwd=None):
        return subprocess.check_output(["git", *args], cwd=cwd or self.work, text=True, stderr=subprocess.STDOUT).strip()

    def release(self, tag="v1.0.0", sha=None):
        return subprocess.run([os.sys.executable, str(SCRIPT)], cwd=self.work,
                              env={**self.env, "RELEASE_TAG": tag, "RELEASE_SHA": sha or self.commits.get(tag, "")},
                              text=True, capture_output=True)

    def data(self):
        return json.loads(self.state.read_text())

    def published(self, *tags):
        self.state.write_text(json.dumps({"releases": [
            {"tag_name": tag, "draft": False, "prerelease": False} for tag in tags], "calls": []}))

    def alias(self, major="v1"):
        result = self.git("ls-remote", "--refs", "origin", "refs/tags/" + major)
        return result.split()[0] if result else None

    def test_version_validation(self):
        self.assertEqual(module.stable_version("v1.2.3"), (1, 2, 3))
        self.assertEqual(module.stable_version("v0.0.1"), (0, 0, 1))
        for tag in ["v1", "1.0.0", "v01.0.0", "v1.0.0-rc.1", "v1.0.0+build", "v1.0.0;echo bad"]:
            with self.subTest(tag=tag):
                self.assertIsNone(module.stable_version(tag))
                self.assertNotEqual(self.release(tag).returncode, 0)
        self.assertEqual(self.data()["calls"], [])

    def test_initial_annotated_release_and_major_alias(self):
        result = self.release()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.alias(), self.commits["v1.0.0"])
        calls = [c for c in self.data()["calls"] if c[:2] == ["release", "create"]]
        self.assertEqual(len(calls), 1)
        self.assertIn("--latest=true", calls[0])
        self.assertIn("--verify-tag", calls[0])
        # Higher pushed but unpublished tags do not advance v1.
        self.assertNotEqual(self.alias(), self.commits["v1.2.0"])

    def test_rerun_is_idempotent(self):
        self.assertEqual(self.release().returncode, 0)
        result = self.release()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len([c for c in self.data()["calls"] if c[:2] == ["release", "create"]]), 1)
        self.assertEqual(self.alias(), self.commits["v1.0.0"])

    def test_minor_release_advances_alias_without_moving_fixed_tag(self):
        self.assertEqual(self.release().returncode, 0)
        original = self.git("ls-remote", "--refs", "origin", "refs/tags/v1.0.0")
        result = self.release("v1.1.0")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.alias(), self.commits["v1.1.0"])
        self.assertEqual(self.git("ls-remote", "--refs", "origin", "refs/tags/v1.0.0"), original)

    def test_older_backfill_does_not_downgrade_major_or_latest(self):
        self.published("v1.2.0", "v2.0.0")
        result = self.release()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.alias(), self.commits["v1.2.0"])
        create = next(c for c in self.data()["calls"] if c[:2] == ["release", "create"])
        self.assertIn("--latest=false", create)

    def test_different_major_keeps_previous_alias(self):
        self.assertEqual(self.release().returncode, 0)
        result = self.release("v2.0.0")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.alias("v1"), self.commits["v1.0.0"])
        self.assertEqual(self.alias("v2"), self.commits["v2.0.0"])

    def test_wrong_verified_commit_does_not_publish(self):
        self.assertNotEqual(self.release(sha="0" * 40).returncode, 0)
        self.assertEqual(self.data()["calls"], [])
        self.assertIsNone(self.alias())

    def test_moved_remote_release_tag_does_not_publish(self):
        self.git("push", "--force", "origin", self.commits["v1.1.0"] + ":refs/tags/v1.0.0")
        self.assertNotEqual(self.release().returncode, 0)
        self.assertEqual(self.data()["calls"], [])
        self.assertIsNone(self.alias())

    def test_draft_or_prerelease_is_not_silently_published(self):
        for flag in ["draft", "prerelease"]:
            self.state.write_text(json.dumps({"releases": [{"tag_name": "v1.0.0", flag: True}], "calls": []}))
            self.assertNotEqual(self.release().returncode, 0)
            self.assertIsNone(self.alias())

    def test_release_attached_to_major_alias_requires_manual_resolution(self):
        self.published("v1")
        result = self.release()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("must remain movable", result.stderr)
        self.assertIsNone(self.alias())


if __name__ == "__main__":
    unittest.main(verbosity=2)
