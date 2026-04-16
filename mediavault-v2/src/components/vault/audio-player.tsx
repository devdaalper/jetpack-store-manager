"use client";

import { useRef, useEffect } from "react";
import type { PlayerState } from "@/hooks/useAudioPlayer";

interface AudioPlayerProps {
  state: PlayerState;
  onClose: () => void;
  onPlay: () => void;
  onPause: () => void;
}

export function AudioPlayer({ state, onClose, onPlay, onPause }: AudioPlayerProps) {
  const mediaRef = useRef<HTMLAudioElement | HTMLVideoElement>(null);

  // Pause media when limit reached
  useEffect(() => {
    if (state.limitReached && mediaRef.current) {
      mediaRef.current.pause();
    }
  }, [state.limitReached]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (mediaRef.current) {
        mediaRef.current.pause();
        mediaRef.current.src = "";
      }
    };
  }, []);

  if (!state.isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={onClose}
      />

      {/* Modal */}
      <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-neutral-100">
          <div className="min-w-0 flex-1">
            <h3 className="text-sm font-semibold text-neutral-900 truncate">
              {state.name}
            </h3>
            {state.remainingPlays >= 0 && (
              <p className="text-xs text-neutral-500 mt-0.5">
                {state.remainingPlays} reproducciones restantes
              </p>
            )}
          </div>
          <button
            onClick={onClose}
            className="ml-3 p-1.5 rounded-lg hover:bg-neutral-100 text-neutral-400 hover:text-neutral-600 transition"
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Content */}
        <div className="p-6">
          {state.isLoading && (
            <div className="flex items-center justify-center py-12">
              <div className="w-8 h-8 border-2 border-orange-200 border-t-orange-600 rounded-full animate-spin" />
            </div>
          )}

          {state.error && (
            <div className="text-center py-8">
              <p className="text-sm text-red-600 mb-2">{state.error}</p>
              {state.limitReached && (
                <p className="text-xs text-neutral-500">
                  Las reproducciones se reinician cada mes.
                </p>
              )}
            </div>
          )}

          {!state.isLoading && !state.error && state.url && (
            <>
              {state.mediaType === "video" ? (
                <video
                  ref={mediaRef as React.RefObject<HTMLVideoElement>}
                  src={state.url}
                  controls
                  autoPlay
                  className="w-full rounded-lg bg-black"
                  onPlay={onPlay}
                  onPause={onPause}
                />
              ) : (
                <div>
                  {/* Audio visualization placeholder */}
                  <div className="w-full h-32 bg-gradient-to-br from-neutral-800 to-neutral-900 rounded-lg flex items-center justify-center mb-4">
                    <span className="text-4xl">🎵</span>
                  </div>
                  <audio
                    ref={mediaRef as React.RefObject<HTMLAudioElement>}
                    src={state.url}
                    controls
                    autoPlay
                    className="w-full"
                    onPlay={onPlay}
                    onPause={onPause}
                  />
                </div>
              )}

              {/* 60s limit overlay */}
              {state.limitReached && (
                <div className="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-center">
                  <p className="text-sm font-medium text-amber-800">
                    Vista previa finalizada (60s)
                  </p>
                  <p className="text-xs text-amber-600 mt-1">
                    Adquiere un plan para reproducción completa.
                  </p>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
