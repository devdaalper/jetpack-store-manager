"use client";

import { useRef, useEffect, useCallback, useState } from "react";
import { Play, Pause, Volume2, VolumeX, X, MessageCircle } from "lucide-react";
import { PREVIEW_DURATION_LIMIT_SECONDS } from "@/domain/types";

export interface PlayerTrack {
  url: string;
  name: string;
  path: string;
  mediaType: "audio" | "video";
}

interface PlayerBarProps {
  track: PlayerTrack | null;
  remainingPlays: number;
  onClose: () => void;
  whatsappNumber?: string | undefined;
}

export function PlayerBar({ track, remainingPlays, onClose, whatsappNumber }: PlayerBarProps) {
  const mediaRef = useRef<HTMLAudioElement>(null);
  const [isPlaying, setIsPlaying] = useState(false);
  const [currentTime, setCurrentTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [isMuted, setIsMuted] = useState(false);
  const [limitReached, setLimitReached] = useState(false);
  const accumulatedRef = useRef(0);
  const fadeIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Reset state when track changes
  useEffect(() => {
    accumulatedRef.current = 0;
    setLimitReached(false);
    setCurrentTime(0);
    setIsPlaying(false);
  }, [track?.url]);

  // Time tracking + fade logic
  useEffect(() => {
    const media = mediaRef.current;
    if (!media) return;

    function onTimeUpdate() {
      if (!media) return;
      const t = media.currentTime;
      setCurrentTime(t);
      accumulatedRef.current = t;

      // Start fade at 50s
      if (t >= 50 && t < PREVIEW_DURATION_LIMIT_SECONDS && !limitReached) {
        const fadeProgress = (t - 50) / 10; // 0 to 1 over 10 seconds
        media.volume = Math.max(0, 1 - fadeProgress * 0.7);
      }

      // Hard stop at 60s
      if (t >= PREVIEW_DURATION_LIMIT_SECONDS && !limitReached) {
        media.pause();
        media.volume = 1;
        setLimitReached(true);
        setIsPlaying(false);
      }
    }

    function onPlay() { setIsPlaying(true); }
    function onPause() { setIsPlaying(false); }
    function onLoaded() { setDuration(media?.duration ?? 0); }

    media.addEventListener("timeupdate", onTimeUpdate);
    media.addEventListener("play", onPlay);
    media.addEventListener("pause", onPause);
    media.addEventListener("loadedmetadata", onLoaded);

    return () => {
      media.removeEventListener("timeupdate", onTimeUpdate);
      media.removeEventListener("play", onPlay);
      media.removeEventListener("pause", onPause);
      media.removeEventListener("loadedmetadata", onLoaded);
    };
  }, [track?.url, limitReached]);

  const togglePlay = useCallback(() => {
    const media = mediaRef.current;
    if (!media || limitReached) return;
    if (isPlaying) {
      media.pause();
    } else {
      media.play();
    }
  }, [isPlaying, limitReached]);

  const toggleMute = useCallback(() => {
    const media = mediaRef.current;
    if (!media) return;
    media.muted = !media.muted;
    setIsMuted(!isMuted);
  }, [isMuted]);

  const seek = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
    const media = mediaRef.current;
    if (!media || limitReached) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const pct = (e.clientX - rect.left) / rect.width;
    const maxSeek = Math.min(duration, PREVIEW_DURATION_LIMIT_SECONDS);
    media.currentTime = pct * maxSeek;
  }, [duration, limitReached]);

  if (!track) return null;

  const progress = duration > 0
    ? (currentTime / Math.min(duration, PREVIEW_DURATION_LIMIT_SECONDS)) * 100
    : 0;

  const whatsappUrl = whatsappNumber
    ? `https://wa.me/${whatsappNumber}?text=${encodeURIComponent("Hola, me interesa obtener acceso completo a JetPack Store.")}`
    : undefined;

  return (
    <div className="fixed bottom-0 left-0 right-0 z-50 bg-neutral-900 text-white border-t border-neutral-800">
      {/* Hidden audio element */}
      <audio ref={mediaRef} src={track.url} autoPlay preload="metadata" />

      {/* Progress bar (clickable) */}
      <div
        className="h-1 bg-neutral-800 cursor-pointer group"
        onClick={seek}
      >
        <div
          className="h-full bg-orange-500 transition-all duration-200 relative"
          style={{ width: `${Math.min(progress, 100)}%` }}
        >
          <div className="absolute right-0 top-1/2 -translate-y-1/2 w-3 h-3 bg-white rounded-full opacity-0 group-hover:opacity-100 transition" />
        </div>
      </div>

      {/* Controls */}
      <div className="flex items-center gap-3 px-4 h-14 md:h-16">
        {/* Play/Pause */}
        <button
          onClick={togglePlay}
          disabled={limitReached}
          className="p-2 rounded-full hover:bg-white/10 transition disabled:opacity-40"
        >
          {isPlaying ? <Pause className="w-5 h-5" /> : <Play className="w-5 h-5" />}
        </button>

        {/* Track info */}
        <div className="flex-1 min-w-0">
          {limitReached ? (
            <div className="flex items-center gap-2">
              <p className="text-sm text-orange-400 font-medium truncate">
                Preview completo
              </p>
              {whatsappUrl && (
                <a
                  href={whatsappUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1 px-3 py-1 bg-green-600 hover:bg-green-700 rounded-full text-xs font-medium transition whitespace-nowrap"
                >
                  <MessageCircle className="w-3.5 h-3.5" />
                  Acceso completo
                </a>
              )}
            </div>
          ) : (
            <p className="text-sm truncate">{track.name}</p>
          )}
        </div>

        {/* Time */}
        <span className="text-xs text-neutral-400 tabular-nums hidden sm:block">
          {formatTime(currentTime)} / {formatTime(Math.min(duration, PREVIEW_DURATION_LIMIT_SECONDS))}
        </span>

        {/* Volume */}
        <button
          onClick={toggleMute}
          className="p-2 rounded-full hover:bg-white/10 transition hidden sm:block"
        >
          {isMuted ? <VolumeX className="w-4 h-4" /> : <Volume2 className="w-4 h-4" />}
        </button>

        {/* Close */}
        <button
          onClick={onClose}
          className="p-2 rounded-full hover:bg-white/10 transition"
        >
          <X className="w-4 h-4" />
        </button>
      </div>
    </div>
  );
}

function formatTime(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m}:${s.toString().padStart(2, "0")}`;
}
