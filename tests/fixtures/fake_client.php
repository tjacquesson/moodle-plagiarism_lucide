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

namespace plagiarism_lucide;

use plagiarism_lucide\api\api_exception;
use plagiarism_lucide\api\client;

/**
 * Scripted stand-in for the Lucide API. Each method pops the next answer
 * queued for it; an api_exception in the script is thrown instead.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_client extends client {

    /** @var array method => list of answers */
    public $script = [];
    /** @var array list of [method, args] */
    public $calls = [];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct('test-key');
    }

    /**
     * Queue an answer for a method.
     *
     * @param string $method
     * @param array|api_exception $answer
     * @return self
     */
    public function will(string $method, $answer): self {
        $this->script[$method][] = $answer;
        return $this;
    }

    /**
     * Count the calls to a method.
     *
     * @param string $method
     * @return int
     */
    public function count(string $method): int {
        return count(array_filter($this->calls, fn($c) => $c[0] === $method));
    }

    /**
     * Arguments of every call to a method.
     *
     * @param string $method
     * @return array
     */
    public function args(string $method): array {
        return array_values(array_map(fn($c) => $c[1], array_filter($this->calls, fn($c) => $c[0] === $method)));
    }

    /**
     * Play the next answer.
     *
     * @param string $method
     * @param array $args
     * @return array
     */
    protected function play(string $method, array $args): array {
        $this->calls[] = [$method, $args];
        if (empty($this->script[$method])) {
            throw new \coding_exception("Unexpected call to {$method}");
        }
        $answer = array_shift($this->script[$method]);
        if ($answer instanceof api_exception) {
            throw $answer;
        }
        return $answer;
    }

    public function capabilities(): array {
        return $this->play('capabilities', []);
    }

    public function usage(): array {
        return $this->play('usage', []);
    }

    public function create_analysis(string $idempotencykey, array $body): array {
        return $this->play('create_analysis', [$idempotencykey, $body]);
    }

    public function get_analysis(string $id): array {
        return $this->play('get_analysis', [$id]);
    }

    public function get_report(string $id): array {
        return $this->play('get_report', [$id]);
    }

    public function delete_analysis(string $id): void {
        $this->play('delete_analysis', [$id]);
    }

    public function upload_file(string $path, string $mimetype, string $postname): array {
        return $this->play('upload_file', [$path, $mimetype, $postname]);
    }

    public function get_file(string $id): array {
        return $this->play('get_file', [$id]);
    }

    public function delete_file(string $id): void {
        $this->play('delete_file', [$id]);
    }
}
