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

    /**
     * Registered successfully; WebSocket closed after Ctrl+C / SIGTERM (normal shutdown).
     * Caller should exit and delete the tunnel.
     */
    case Finished;

    /**
     * Registered successfully; WebSocket closed without user stop (idle, proxy, network, edge restart).
     * Caller should keep heartbeats so the tunnel is not torn down immediately.
     */
    case Disconnected;
}
