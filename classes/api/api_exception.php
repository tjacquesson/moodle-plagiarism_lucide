<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace plagiarism_lucide\api;

/**
 * Error returned by the Lucide API, or a network failure before any answer.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \Exception {
    /** The key or the account cannot be used: stop sending until an admin acts. */
    public const KIND_AUTH = 'auth';
    /** Too many requests: pause the whole queue for a while. */
    public const KIND_THROTTLE = 'throttle';
    /** Not enough credits: keep the source, retry later without charging. */
    public const KIND_CREDITS = 'credits';
    /** The content cannot be analysed: final answer, no charge. */
    public const KIND_CONTENT = 'content';
    /** The remote object is gone (expired or deleted). */
    public const KIND_GONE = 'gone';
    /** Network failure, outage or engine unavailable: retry with backoff. */
    public const KIND_TRANSIENT = 'transient';

    /** @var string Stable error code from the API (or network_error). */
    public $apicode;
    /** @var int HTTP status, 0 when no answer was received. */
    public $httpstatus;
    /** @var bool Whether the API says the call may succeed later. */
    public $retryable;
    /** @var int Seconds to wait before retrying, 0 when unknown. */
    public $retryafter;
    /** @var array Error details from the API. */
    public $details;

    /**
     * Constructor.
     *
     * @param string $apicode
     * @param string $message
     * @param int $httpstatus
     * @param bool $retryable
     * @param int $retryafter
     * @param array $details
     */
    public function __construct(
        string $apicode,
        string $message,
        int $httpstatus = 0,
        bool $retryable = false,
        int $retryafter = 0,
        array $details = []
    ) {
        parent::__construct($message);
        $this->apicode = $apicode;
        $this->httpstatus = $httpstatus;
        $this->retryable = $retryable;
        $this->retryafter = $retryafter;
        $this->details = $details;
    }

    /**
     * Sort the error into what the queue should do with it.
     *
     * @return string One of the KIND_* constants.
     */
    public function kind(): string {
        $code = $this->apicode;
        if (
            $this->httpstatus === 401 || in_array($code, ['invalid_token', 'token_expired', 'entitlement_expired',
                'insufficient_scope', 'feature_not_available'], true)
        ) {
            return self::KIND_AUTH;
        }
        if (in_array($code, ['insufficient_credits', 'credit_replenishment_pending'], true)) {
            return self::KIND_CREDITS;
        }
        if ($code === 'credit_budget_exceeded') {
            // Either the daily cap of the key (wait) or max_credits below the cost (final).
            return isset($this->details['daily_limit_cents']) ? self::KIND_CREDITS : self::KIND_CONTENT;
        }
        if (
            $this->httpstatus === 429 || in_array($code, ['rate_limit_exceeded', 'word_rate_exceeded',
                'queue_limit_exceeded', 'file_storage_limit_exceeded'], true)
        ) {
            return self::KIND_THROTTLE;
        }
        if (
            in_array($code, ['not_found', 'analysis_expired', 'analysis_deleted', 'file_expired'], true)
                || $this->httpstatus === 404 || $this->httpstatus === 410
        ) {
            return self::KIND_GONE;
        }
        if ($this->httpstatus === 0 || $this->httpstatus >= 500 || $this->retryable) {
            return self::KIND_TRANSIENT;
        }
        return self::KIND_CONTENT;
    }
}
