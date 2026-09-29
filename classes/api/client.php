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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * HTTP client for the Lucide public API v1.
 *
 * All calls start from the Moodle server. The key travels in the Authorization
 * header only, redirects are never followed, and Moodle proxy settings apply.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {

    /** Production endpoint. */
    const DEFAULT_BASE_URL = 'https://api.lucide.ai/v1';

    /** @var string */
    protected $apikey;
    /** @var string */
    protected $baseurl;

    /**
     * Constructor.
     *
     * @param string|null $apikey Defaults to the key saved in the plugin settings.
     */
    public function __construct(?string $apikey = null) {
        $this->apikey = $apikey ?? (string) get_config('plagiarism_lucide', 'apikey');
        $this->baseurl = self::base_url();
    }

    /**
     * API root. Only a server-side config.php line can change it, for development.
     *
     * @return string
     */
    public static function base_url(): string {
        global $CFG;
        $url = !empty($CFG->plagiarism_lucide_apiurl) ? $CFG->plagiarism_lucide_apiurl : self::DEFAULT_BASE_URL;
        return rtrim($url, '/');
    }

    /**
     * Account model, formats and limits. Free, sends no text.
     *
     * @return array
     */
    public function capabilities(): array {
        return $this->request('GET', '/capabilities');
    }

    /**
     * Credit balance and daily budget of the key. Free, sends no text.
     *
     * @return array
     */
    public function usage(): array {
        return $this->request('GET', '/usage');
    }

    /**
     * Admit an analysis. Replaying the same key with the same body returns the same analysis.
     *
     * @param string $idempotencykey
     * @param array $body content or file_id, max_credits, title, retention
     * @return array Analysis
     */
    public function create_analysis(string $idempotencykey, array $body): array {
        return $this->request('POST', '/analyses', ['json' => $body, 'idempotencykey' => $idempotencykey]);
    }

    /**
     * Read an analysis status and compact result.
     *
     * @param string $id
     * @return array
     */
    public function get_analysis(string $id): array {
        return $this->request('GET', '/analyses/' . rawurlencode($id));
    }

    /**
     * Full report of a completed analysis: analysed text and every segment.
     *
     * @param string $id
     * @return array
     */
    public function get_report(string $id): array {
        return $this->request('GET', '/analyses/' . rawurlencode($id) . '/report', ['timeout' => 30]);
    }

    /**
     * Erase an analysis and its text at Lucide. Already gone counts as done.
     *
     * @param string $id
     */
    public function delete_analysis(string $id): void {
        try {
            $this->request('DELETE', '/analyses/' . rawurlencode($id));
        } catch (api_exception $e) {
            if ($e->kind() !== api_exception::KIND_GONE) {
                throw $e;
            }
        }
    }

    /**
     * Upload a document for text extraction. The file name sent is neutral.
     *
     * @param string $path Local temporary copy
     * @param string $mimetype
     * @param string $postname Neutral name such as submission.pdf
     * @return array File
     */
    public function upload_file(string $path, string $mimetype, string $postname): array {
        return $this->request('POST', '/files', [
            'multipart' => ['file' => new \CURLFile($path, $mimetype, $postname)],
            'timeout' => 60,
        ]);
    }

    /**
     * Read the extraction status of an uploaded document.
     *
     * @param string $id
     * @return array File
     */
    public function get_file(string $id): array {
        return $this->request('GET', '/files/' . rawurlencode($id));
    }

    /**
     * Remove an uploaded document. Already gone counts as done.
     *
     * @param string $id
     */
    public function delete_file(string $id): void {
        try {
            $this->request('DELETE', '/files/' . rawurlencode($id));
        } catch (api_exception $e) {
            if ($e->kind() !== api_exception::KIND_GONE) {
                throw $e;
            }
        }
    }

    /**
     * Build the curl object. Separate so tests can replace the transport.
     *
     * @return \curl
     */
    protected function make_curl(): \curl {
        // A development endpoint on localhost would be refused by the curl security helper.
        return new \curl(['ignoresecurity' => self::base_url() !== self::DEFAULT_BASE_URL && !empty($GLOBALS['CFG']->debugdeveloper)]);
    }

    /**
     * Perform one request and decode the answer.
     *
     * @param string $method GET, POST or DELETE
     * @param string $path
     * @param array $options json, multipart, idempotencykey, timeout
     * @return array Decoded JSON body, empty for 204
     * @throws api_exception
     */
    protected function request(string $method, string $path, array $options = []): array {
        global $CFG;

        if ($this->apikey === '') {
            throw new api_exception('missing_key', 'No Lucide API key configured.', 401);
        }

        $curl = $this->make_curl();
        $headers = [
            'Authorization: Bearer ' . $this->apikey,
            'Accept: application/json',
            'X-Lucide-Client: moodle-plagiarism_lucide/' . self::plugin_release(),
        ];
        if (!empty($options['idempotencykey'])) {
            $headers[] = 'Idempotency-Key: ' . $options['idempotencykey'];
        }
        $postdata = null;
        if (isset($options['json'])) {
            $headers[] = 'Content-Type: application/json';
            $postdata = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else if (isset($options['multipart'])) {
            $postdata = $options['multipart'];
        }
        $curl->setHeader($headers);

        $curloptions = [
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_TIMEOUT' => $options['timeout'] ?? 15,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_USERAGENT' => 'Moodle/' . ($CFG->release ?? '') . ' plagiarism_lucide/' . self::plugin_release(),
        ];

        $url = $this->baseurl . $path;
        switch ($method) {
            case 'GET':
                $body = $curl->get($url, [], $curloptions);
                break;
            case 'POST':
                $body = $curl->post($url, $postdata ?? '', $curloptions);
                break;
            case 'DELETE':
                // Not curl::delete(), which adds a Basic authorization header.
                $body = $curl->get($url, [], $curloptions + ['CURLOPT_CUSTOMREQUEST' => 'DELETE']);
                break;
            default:
                throw new \coding_exception('Unsupported method ' . $method);
        }

        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno() || $status === 0) {
            // Includes a URL refused by the Moodle curl security helper.
            throw new api_exception('network_error', 'Lucide could not be reached: ' . $curl->error, 0, true);
        }
        $data = ($body === '' || $body === null) ? [] : json_decode($body, true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new api_exception('invalid_response', 'Lucide answered with an unreadable body.', $status, true);
            }
            return $data;
        }

        $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
        throw new api_exception(
            (string) ($error['code'] ?? 'http_' . $status),
            (string) ($error['message'] ?? 'Unexpected HTTP status ' . $status),
            $status,
            (bool) ($error['retryable'] ?? ($status >= 500)),
            (int) ($error['retry_after_seconds'] ?? 0),
            is_array($error['details'] ?? null) ? $error['details'] : []
        );
    }

    /**
     * Release string of this plugin.
     *
     * @return string
     */
    public static function plugin_release(): string {
        $release = \core_plugin_manager::instance()->get_plugin_info('plagiarism_lucide')->release ?? null;
        return $release ?: 'dev';
    }
}
