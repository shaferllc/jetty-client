<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Outcome of {@see EdgeAgent::run()}: whether the WebSocket agent reached a running state or failed before that.
 */
enum EdgeAgentResult
{
    /** Connect or registration failed; caller may fall back to heartbeats-only. */
    case FailedEarly;

    /** Registered successfully; main loop ran until exit (signal, close, etc.). */
    case Finished;
}
