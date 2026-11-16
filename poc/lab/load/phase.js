// Which part of the scenario a request belongs to, by the time it is SENT (simulator-spec.md section 7.4.3). Used by scenario.js;
// kept in its own file so a test can run it on fixed times without a clock.
//
//   warmup   the discarded start: before warmupSec
//   measure  the kept window: from warmupSec up to (not including) durationSec
//   after    a request sent at or after the window's end (a stream's last tick can land on the edge, or a little past it): it is
//            not part of the window, so it is counted in no rps check
//
// elapsedMs is the time since the start of the whole scenario (not of one route's stream).
export function phaseAt(elapsedMs, warmupSec, durationSec) {
  if (elapsedMs < warmupSec * 1000) {
    return 'warmup';
  }
  if (elapsedMs >= durationSec * 1000) {
    return 'after';
  }
  return 'measure';
}
