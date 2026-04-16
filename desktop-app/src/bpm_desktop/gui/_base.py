"""Shared base classes for GUI step widgets."""

from __future__ import annotations

from typing import Any, Callable

from PySide6.QtCore import QObject, Signal, Slot
from PySide6.QtWidgets import QWidget

from ..services.app_context import AppContext


class PipelineWorker(QObject):
    """QObject that runs a pipeline task in a QThread."""

    progress = Signal(dict)
    finished = Signal(object)
    failed = Signal(str)

    def __init__(self, task: Callable[..., Any], kwargs: dict[str, Any]) -> None:
        super().__init__()
        self.task = task
        self.kwargs = kwargs

    @Slot()
    def run(self) -> None:
        try:
            result = self.task(progress_callback=self._emit_progress, **self.kwargs)
            self.finished.emit(result)
        except Exception as exc:
            self.failed.emit(str(exc))

    def _emit_progress(self, payload: dict[str, Any]) -> None:
        self.progress.emit(dict(payload or {}))


class BaseStep(QWidget):
    """Abstract base for all wizard-step widgets."""

    completionChanged = Signal(bool)

    def __init__(self, title: str, ctx: AppContext) -> None:
        super().__init__()
        self.title = title
        self.ctx = ctx
        self._completed = False

    @property
    def completed(self) -> bool:
        return self._completed

    def set_completed(self, value: bool) -> None:
        if self._completed == value:
            return
        self._completed = value
        self.completionChanged.emit(value)

    def on_enter(self) -> None:
        return
