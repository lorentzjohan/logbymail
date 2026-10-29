<?php

/**
 * -------------------------------------------------------------------------
 * LogByMail plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 LORENTZ Johan
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/lorentzjohan
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Logbymail;

/**
 * Result of {@see EmailLoginResolver::resolve()}.
 */
final readonly class Resolution
{
    private function __construct(
        public ResolutionStatus $status,
        public string $identifier,
        public ?string $login = null,
        public ?int $users_id = null,
        public int $owners_count = 0,
    ) {}

    public static function refused(
        ResolutionStatus $status,
        string $identifier,
        int $owners_count = 0,
        ?string $login = null,
        ?int $users_id = null,
    ): self {
        return new self($status, $identifier, $login, $users_id, $owners_count);
    }

    public static function resolved(string $identifier, string $login, int $users_id): self
    {
        return new self(ResolutionStatus::RESOLVED, $identifier, $login, $users_id, 1);
    }

    public function isResolved(): bool
    {
        return $this->status === ResolutionStatus::RESOLVED && $this->login !== null;
    }
}
