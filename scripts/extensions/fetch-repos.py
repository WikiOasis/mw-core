#!/usr/bin/env python3
"""
fetch-repos.py — Clone or update MediaWiki extensions and skins.

Reads a repos-<version>.yaml file and ensures each repository is cloned to the
correct path within a MediaWiki version directory.  Already-cloned repos have
their branch checked out and pulled; repos not yet cloned are cloned fresh with
--depth=1 for speed.

Usage:
    python3 fetch-repos.py --repos /srv/mediawiki/scripts/extensions/repos-1.45.yaml
                           --target /srv/mediawiki/versions/1.45

Options:
    --repos   Path to the repos-<version>.yaml file (required)
    --target  Root of the MediaWiki version directory (required)
    --jobs    Number of parallel clone/pull operations (default: 4)
    --update  If set, pull latest commits in already-cloned repos
    --dry-run Print what would be done without doing it
"""

import argparse
import os
import re
import subprocess
import sys
import yaml
from concurrent.futures import ThreadPoolExecutor, as_completed


def expand_version_token(value: str, rel_branch: str) -> str:
    """Replace the literal string _version_ with the resolved REL branch."""
    return value.replace('_version_', rel_branch)


def rel_branch_from_file(repos_file: str) -> str:
    """
    Derive the REL branch from the filename (repos-1.45.yaml → REL1_45)
    or from the _version_ key inside the file.
    """
    basename = os.path.basename(repos_file)
    m = re.match(r'repos-(\d+)\.(\d+)\.yaml', basename)
    if m:
        return f"REL{m.group(1)}_{m.group(2)}"
    return 'master'


def git_clone(url: str, dest: str, branch: str, dry_run: bool = False) -> bool:
    print(f"  [clone] {url} → {dest} (branch: {branch})")
    if dry_run:
        return True
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    result = subprocess.run(
        ['git', 'clone', '--depth=1', '--branch', branch, url, dest],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        print(f"  ERROR cloning {url}:\n{result.stderr}", file=sys.stderr)
        return False
    return True


def git_update(dest: str, branch: str, dry_run: bool = False) -> bool:
    print(f"  [update] {dest} (branch: {branch})")
    if dry_run:
        return True
    # Fetch and checkout the target branch
    for cmd in [
        ['git', 'fetch', '--depth=1', 'origin', branch],
        ['git', 'checkout', branch],
        ['git', 'reset', '--hard', f'origin/{branch}'],
    ]:
        result = subprocess.run(cmd, cwd=dest, capture_output=True, text=True)
        if result.returncode != 0:
            print(f"  ERROR running {' '.join(cmd)} in {dest}:\n{result.stderr}",
                  file=sys.stderr)
            return False
    return True


def process_entry(entry: dict, target: str, rel_branch: str,
                  do_update: bool, dry_run: bool) -> tuple[str, bool]:
    path   = entry['path']
    url    = entry['url']
    branch = expand_version_token(entry.get('branch', '_version_'), rel_branch)
    dest   = os.path.join(target, path)

    if os.path.isdir(os.path.join(dest, '.git')):
        if do_update:
            ok = git_update(dest, branch, dry_run)
        else:
            print(f"  [skip]  {path} already cloned")
            ok = True
    else:
        ok = git_clone(url, dest, branch, dry_run)

    return path, ok


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--repos',   required=True, help='Path to repos-<version>.yaml')
    parser.add_argument('--target',  required=True, help='MediaWiki version root directory')
    parser.add_argument('--jobs',    type=int, default=4, help='Parallel jobs')
    parser.add_argument('--update',  action='store_true', help='Pull latest in existing repos')
    parser.add_argument('--dry-run', action='store_true', help='Print actions without executing')
    args = parser.parse_args()

    if not os.path.isfile(args.repos):
        sys.exit(f"Repos file not found: {args.repos}")

    if not os.path.isdir(args.target):
        sys.exit(f"Target directory not found: {args.target}")

    with open(args.repos) as f:
        data = yaml.safe_load(f) or {}

    rel_branch = data.get('_version_') or rel_branch_from_file(args.repos)
    print(f"REL branch: {rel_branch}")

    entries = []
    for group in ('extensions', 'skins'):
        for entry in data.get(group, []):
            entries.append(entry)

    failures: list[str] = []

    with ThreadPoolExecutor(max_workers=args.jobs) as pool:
        futures = {
            pool.submit(process_entry, e, args.target, rel_branch,
                        args.update, args.dry_run): e['path']
            for e in entries
        }
        for future in as_completed(futures):
            path, ok = future.result()
            if not ok:
                failures.append(path)

    if failures:
        print(f"\nFailed ({len(failures)}):", file=sys.stderr)
        for f in sorted(failures):
            print(f"  {f}", file=sys.stderr)
        sys.exit(1)
    else:
        print(f"\nAll {len(entries)} repos processed successfully.")


if __name__ == '__main__':
    main()
