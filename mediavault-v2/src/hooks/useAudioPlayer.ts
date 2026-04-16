"use client";

import { useState, useRef, useCallback } from "react";
import { PREVIEW_DURATION_LIMIT_SECONDS } from "@/domain/types";

export interface PlayerState {
  isOpen: boolean;
  isLoading: boolean;
  url: string;
  name: string;
  mediaType: "audio" | "video";
  remainingPlays: number;
  limitReached: boolean;
  error: string | null;
}

const INITIAL_STATE: PlayerState = {
  isOpen: false,
  isLoading: false,
  url: "",
  name: "",
  mediaType: "audio",
  remainingPlays: -1,
  limitReached: false,
  error: null,
};

export function useAudioPlayer() {
  const [state, setState] = useState<PlayerState>(INITIAL_STATE);
  const accumulatedTimeRef = useRef(0);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  /**
   * Open the player with a file.
   * Fetches a presigned URL, tracks the play, and opens the modal.
   */
  const play = useCallback(async (path: string, name: string, mediaType: "audio" | "video") => {
    setState((s) => ({ ...s, isOpen: true, isLoading: true, name, mediaType, error: null, limitReached: false }));
    accumulatedTimeRef.current = 0;

    try {
      // 1. Get presigned URL
      const urlRes = await fetch("/api/media/presigned-url", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path, intent: "preview" }),
      });

      const urlData = await urlRes.json();

      if (!urlData.ok) {
        setState((s) => ({
          ...s,
          isLoading: false,
          error: urlData.error?.message ?? "No se pudo cargar el archivo",
          limitReached: urlData.error?.code === "PLAY_LIMIT_REACHED",
        }));
        return;
      }

      // 2. Track the play
      await fetch("/api/media/play", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path }),
      });

      setState((s) => ({
        ...s,
        isLoading: false,
        url: urlData.data.url,
        remainingPlays: urlData.data.remainingPlays,
      }));
    } catch {
      setState((s) => ({
        ...s,
        isLoading: false,
        error: "Error de conexión",
      }));
    }
  }, []);

  /**
   * Start the 60-second preview timer.
   * Called when the media starts playing.
   */
  const startTimer = useCallback(() => {
    if (timerRef.current) return;

    timerRef.current = setInterval(() => {
      accumulatedTimeRef.current += 1;

      if (accumulatedTimeRef.current >= PREVIEW_DURATION_LIMIT_SECONDS) {
        setState((s) => ({ ...s, limitReached: true }));
        if (timerRef.current) {
          clearInterval(timerRef.current);
          timerRef.current = null;
        }
      }
    }, 1000);
  }, []);

  /**
   * Pause the timer (when media is paused).
   */
  const pauseTimer = useCallback(() => {
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  /**
   * Close the player and clean up.
   */
  const close = useCallback(() => {
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
    accumulatedTimeRef.current = 0;
    setState(INITIAL_STATE);
  }, []);

  return { state, play, close, startTimer, pauseTimer };
}
