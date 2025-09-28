<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Logger;

use const ENT_XML1;
use function htmlspecialchars;
use function in_array;
use Infection\Metrics\MetricsCalculator;
use Infection\Metrics\ResultsCollector;
use Infection\Mutant\MutantExecutionResult;
use function pathinfo;
use function sprintf;

/**
 * @internal
 */
final readonly class PiTestLogger implements LineMutationTestingResultsLogger
{
    public function __construct(
        private MetricsCalculator $metricsCalculator,
        private ResultsCollector $resultsCollector,
    ) {
    }

    /**
     * @return array{0: string}
     */
    public function getLogLines(): array
    {
        $mutants = [];

        // Add all execution results
        $allResults = [
            ...$this->resultsCollector->getKilledExecutionResults(),
            ...$this->resultsCollector->getEscapedExecutionResults(),
            ...$this->resultsCollector->getTimedOutExecutionResults(),
            ...$this->resultsCollector->getErrorExecutionResults(),
            ...$this->resultsCollector->getSyntaxErrorExecutionResults(),
            ...$this->resultsCollector->getNotCoveredExecutionResults(),
            ...$this->resultsCollector->getIgnoredExecutionResults(),
        ];

        foreach ($allResults as $result) {
            $mutants[] = $this->createMutantElement($result);
        }

        $xml = $this->generateXmlReport($mutants);

        return [$xml];
    }

    private function createMutantElement(MutantExecutionResult $result): string
    {
        $status = $this->getStatus($result);
        $mutatorName = htmlspecialchars($result->getMutatorName(), ENT_XML1);
        $sourceFile = htmlspecialchars($result->getOriginalFilePath(), ENT_XML1);
        $line = $result->getOriginalStartingLine();

        return sprintf(
            '    <mutation detected="%s" status="%s" numberOfTestsRun="0">' . "\n"
            . '      <sourceFile>%s</sourceFile>' . "\n"
            . '      <mutatedClass>%s</mutatedClass>' . "\n"
            . '      <mutatedMethod>%s</mutatedMethod>' . "\n"
            . '      <lineNumber>%d</lineNumber>' . "\n"
            . '      <mutator>%s</mutator>' . "\n"
            . '      <index>%d</index>' . "\n"
            . '      <killingTest/>' . "\n"
            . '      <description>%s</description>' . "\n"
            . '    </mutation>',
            $status === 'KILLED' ? 'true' : 'false',
            $status,
            $sourceFile,
            $this->extractClassName($sourceFile),
            'unknown',
            $line,
            $mutatorName,
            0,
            htmlspecialchars($mutatorName, ENT_XML1),
        );
    }

    private function getStatus(MutantExecutionResult $result): string
    {
        if (in_array($result, $this->resultsCollector->getKilledExecutionResults(), true)) {
            return 'KILLED';
        }

        if (in_array($result, $this->resultsCollector->getEscapedExecutionResults(), true)) {
            return 'SURVIVED';
        }

        if (in_array($result, $this->resultsCollector->getTimedOutExecutionResults(), true)) {
            return 'TIMED_OUT';
        }

        if (in_array($result, $this->resultsCollector->getErrorExecutionResults(), true)
            || in_array($result, $this->resultsCollector->getSyntaxErrorExecutionResults(), true)) {
            return 'COMPILE_ERROR';
        }

        if (in_array($result, $this->resultsCollector->getNotCoveredExecutionResults(), true)) {
            return 'NO_COVERAGE';
        }

        return 'UNKNOWN';
    }

    private function extractClassName(string $filePath): string
    {
        $pathInfo = pathinfo($filePath);

        return $pathInfo['filename'] ?? 'Unknown';
    }

    /**
     * @param array<string> $mutants
     */
    private function generateXmlReport(array $mutants): string
    {
        $stats = $this->metricsCalculator;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= sprintf(
            '<mutations>' . "\n"
            . '  <mutation_summary>' . "\n"
            . '    <line_coverage>%.2f</line_coverage>' . "\n"
            . '    <mutation_coverage>%.2f</mutation_coverage>' . "\n"
            . '    <test_strength>%.2f</test_strength>' . "\n"
            . '  </mutation_summary>' . "\n",
            $stats->getCoverageRate(),
            $stats->getMutationScoreIndicator(),
            $stats->getCoveredCodeMutationScoreIndicator(),
        );

        foreach ($mutants as $mutant) {
            $xml .= $mutant . "\n";
        }

        $xml .= '</mutations>';

        return $xml;
    }
}
