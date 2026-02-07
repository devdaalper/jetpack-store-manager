#!/usr/bin/env python3
"""Manage per-workspace _ai_knowledge notes."""

from __future__ import annotations

import argparse
import re
import subprocess
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path


README_CONTENT = """# _ai_knowledge

Technical memory for this workspace.

## Rule

Read this folder before editing code.
Write one brief technical note when finishing a task or resolving a hard bug.
"""


INDEX_HEADER = """# AI Knowledge Index

Use this index to find previous technical lessons quickly.

## Notes
"""


@dataclass
class MemoryPaths:
    workspace_root: Path
    memory_dir: Path
    index_file: Path
    readme_file: Path


def slugify(value: str) -> str:
    cleaned = re.sub(r"[^a-zA-Z0-9]+", "-", value.strip().lower())
    cleaned = re.sub(r"-{2,}", "-", cleaned).strip("-")
    return cleaned or "task"


def detect_workspace_root(start: Path) -> Path:
    try:
        result = subprocess.run(
            ["git", "rev-parse", "--show-toplevel"],
            cwd=start,
            check=True,
            capture_output=True,
            text=True,
        )
        root = result.stdout.strip()
        if root:
            return Path(root).resolve()
    except Exception:
        pass
    return start.resolve()


def build_paths(workspace_arg: str) -> MemoryPaths:
    start = Path(workspace_arg).expanduser()
    root = detect_workspace_root(start)
    memory_dir = root / "_ai_knowledge"
    return MemoryPaths(
        workspace_root=root,
        memory_dir=memory_dir,
        index_file=memory_dir / "INDEX.md",
        readme_file=memory_dir / "README.md",
    )


def ensure_layout(paths: MemoryPaths) -> None:
    paths.memory_dir.mkdir(parents=True, exist_ok=True)
    if not paths.readme_file.exists():
        paths.readme_file.write_text(README_CONTENT, encoding="utf-8")
    if not paths.index_file.exists():
        paths.index_file.write_text(INDEX_HEADER, encoding="utf-8")


def list_note_files(memory_dir: Path) -> list[Path]:
    notes: list[Path] = []
    for file_path in memory_dir.glob("*.md"):
        if file_path.name in {"INDEX.md", "README.md"}:
            continue
        notes.append(file_path)
    return sorted(notes, key=lambda path: path.name, reverse=True)


def format_note(
    title: str,
    context: str,
    worked: str,
    failed: str,
    improve: str,
    created_at: datetime,
) -> str:
    timestamp = created_at.strftime("%Y-%m-%d %H:%M")
    return (
        f"# {title}\n\n"
        f"- Date: {timestamp}\n\n"
        "## Context\n\n"
        f"{context.strip()}\n\n"
        "## What worked\n\n"
        f"{worked.strip()}\n\n"
        "## What failed\n\n"
        f"{failed.strip()}\n\n"
        "## Next time\n\n"
        f"{improve.strip()}\n"
    )


def update_index(paths: MemoryPaths, created_at: datetime, title: str, note_path: Path) -> None:
    if paths.index_file.exists():
        lines = paths.index_file.read_text(encoding="utf-8").splitlines()
    else:
        lines = INDEX_HEADER.splitlines()

    if "## Notes" not in lines:
        lines.extend(["", "## Notes"])

    note_line = (
        f"- {created_at.strftime('%Y-%m-%d %H:%M')} - "
        f"[{title}](./{note_path.name})"
    )
    if note_line not in lines:
        insert_at = lines.index("## Notes") + 1
        while insert_at < len(lines) and lines[insert_at].strip() == "":
            insert_at += 1
        lines.insert(insert_at, note_line)

    paths.index_file.write_text("\n".join(lines).rstrip() + "\n", encoding="utf-8")


def command_start(args: argparse.Namespace) -> int:
    paths = build_paths(args.workspace)
    ensure_layout(paths)

    notes = list_note_files(paths.memory_dir)[: args.limit]
    print(f"WORKSPACE={paths.workspace_root}")
    print(f"MEMORY_DIR={paths.memory_dir}")
    print(f"READ={paths.index_file}")
    for note in notes:
        print(f"READ={note}")
    return 0


def command_finish(args: argparse.Namespace) -> int:
    paths = build_paths(args.workspace)
    ensure_layout(paths)

    created_at = datetime.now()
    slug = slugify(args.title)
    base_name = f"{created_at.strftime('%Y-%m-%d-%H%M')}-{slug}.md"
    note_path = paths.memory_dir / base_name

    counter = 2
    while note_path.exists():
        note_path = paths.memory_dir / f"{created_at.strftime('%Y-%m-%d-%H%M')}-{slug}-{counter}.md"
        counter += 1

    note_body = format_note(
        title=args.title,
        context=args.context,
        worked=args.worked,
        failed=args.failed,
        improve=args.improve,
        created_at=created_at,
    )
    note_path.write_text(note_body, encoding="utf-8")
    update_index(paths, created_at, args.title, note_path)

    print(f"WORKSPACE={paths.workspace_root}")
    print(f"NOTE={note_path}")
    print(f"INDEX={paths.index_file}")
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Create and maintain _ai_knowledge notes for any workspace.",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    start = subparsers.add_parser("start", help="Ensure memory folder and print files to read.")
    start.add_argument("--workspace", default=".", help="Workspace path.")
    start.add_argument("--limit", type=int, default=5, help="Max recent note files to print.")
    start.set_defaults(func=command_start)

    finish = subparsers.add_parser("finish", help="Write one technical note and update index.")
    finish.add_argument("--workspace", default=".", help="Workspace path.")
    finish.add_argument("--title", required=True, help="Short note title.")
    finish.add_argument("--context", required=True, help="Context and scope of work.")
    finish.add_argument("--worked", required=True, help="What worked.")
    finish.add_argument("--failed", required=True, help="What failed.")
    finish.add_argument("--improve", required=True, help="How to improve next time.")
    finish.set_defaults(func=command_finish)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
