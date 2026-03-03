<?php

 

/**

 * Item Response Theory (IRT) Implementation

 * Using 3-Parameter Logistic (3PL) Model

 *

 * P(θ) = c + (1-c) / (1 + e^(-Da(θ-b)))

 *

 * where:

 * θ (theta) = person ability

 * a = item discrimination

 * b = item difficulty

 * c = guessing parameter

 * D = scaling constant (1.7 to approximate normal ogive)

 */

 

class ItemResponseTheory {

 

    // Scaling constant (1.7 approximates normal ogive, 1.0 for logistic)

    private $D = 1.0;

 

    /**

     * Calculate probability of correct response using 3PL model

     *

     * @param float $theta Person ability

     * @param float $a Item discrimination

     * @param float $b Item difficulty

     * @param float $c Guessing parameter (default 0.25 for 4-choice MCQ)

     * @return float Probability between 0 and 1

     */

    public function calculateProbability($theta, $a, $b, $c = 0.25) {

        // Ensure c is within valid range

        $c = max(0, min(0.5, $c));

 

        $exponent = -$this->D * $a * ($theta - $b);

 

        // Prevent overflow

        if ($exponent > 700) {

            return $c;

        } elseif ($exponent < -700) {

            return 1.0;

        }

 

        $probability = $c + ((1 - $c) / (1 + exp($exponent)));

 

        return $probability;

    }

 

    /**

     * Calculate item information at a given theta

     * Higher information = more precise measurement

     *

     * Correct 3PL Information Formula:

     * I(θ) = D²a² * [(P(θ) - c)² / ((1-c)² * P(θ) * Q(θ))]

     *

     * @param float $theta Person ability

     * @param float $a Item discrimination

     * @param float $b Item difficulty

     * @param float $c Guessing parameter

     * @return float Information value

     */

    public function calculateInformation($theta, $a, $b, $c = 0.25) {

        $P = $this->calculateProbability($theta, $a, $b, $c);

        $Q = 1 - $P;

 

        // Prevent division by zero

        if ($P <= $c || $Q <= 0 || $P >= 1) {

            return 0.0001; // Return small value instead of 0

        }

 

        // Correct 3PL information formula

        $numerator = pow($this->D, 2) * pow($a, 2) * pow(($P - $c), 2);

        $denominator = pow((1 - $c), 2) * $P * $Q;

 

        if ($denominator == 0) return 0.0001;

 

        $information = $numerator / $denominator;

 

        return $information;

    }

 

    /**

     * Estimate ability (theta) using Maximum Likelihood Estimation

     * Using Newton-Raphson algorithm with 3PL derivatives

     *

     * @param array $responses Array of ['isCorrect', 'a', 'b', 'c']

     * @param float $initialTheta Starting ability estimate

     * @param int $maxIterations Maximum Newton-Raphson iterations

     * @param float $tolerance Convergence threshold

     * @return float Estimated theta

     */

    public function estimateAbility($responses, $initialTheta = 0.0, $maxIterations = 50, $tolerance = 0.001) {

        // Handle empty responses

        if (empty($responses)) {

            return $initialTheta;

        }

 

        // Handle extreme initial values

        if ($initialTheta >= 2.5) {

            $theta = 1.5;

        } elseif ($initialTheta <= -2.5) {

            $theta = -1.5;

        } else {

            $theta = $initialTheta;

        }

 

        // Check for all correct or all incorrect (MLE doesn't converge well)

        $correctCount = 0;

        $totalCount = count($responses);

        foreach ($responses as $response) {

            if ($response['isCorrect']) $correctCount++;

        }

 

        // Handle edge cases

        if ($correctCount == $totalCount) {

            // All correct - estimate high ability

            $maxB = -3;

            foreach ($responses as $r) {

                if ($r['b'] > $maxB) $maxB = $r['b'];

            }

            return min(3.0, $maxB + 1.5);

        }

 

        if ($correctCount == 0) {

            // All incorrect - estimate low ability

            $minB = 3;

            foreach ($responses as $r) {

                if ($r['b'] < $minB) $minB = $r['b'];

            }

            return max(-3.0, $minB - 1.5);

        }

 

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {

            $firstDerivative = 0;

            $secondDerivative = 0;

 

            foreach ($responses as $response) {

                $u = $response['isCorrect'] ? 1 : 0;

                $a = (float)$response['a'];

                $b = (float)$response['b'];

                $c = (float)($response['c'] ?? 0.25);

 

                // Ensure valid parameters

                $a = max(0.1, $a);

                $c = max(0, min(0.5, $c));

 

                $P = $this->calculateProbability($theta, $a, $b, $c);

                $Q = 1 - $P;

 

                // Prevent numerical issues

                $P = max(0.0001, min(0.9999, $P));

                $Q = max(0.0001, min(0.9999, $Q));

 

                // 3PL First Derivative (correct formula)

                // L'(θ) = Da(u-P)(P-c) / (P(1-c))

                $pStarNumerator = $P - $c;

                $pStarDenominator = 1 - $c;

 

                if ($pStarDenominator == 0) continue;

 

                $pStar = $pStarNumerator / $pStarDenominator; // P* in IRT literature

 

                $firstDerivative += $this->D * $a * ($u - $P) * $pStar / $P;

 

                // 3PL Second Derivative (correct formula)

                // L''(θ) = -D²a²(P-c)[(u-P)(P(1+c)-2cP-c) + P(P-c)Q] / [P²(1-c)²]

                $secondDerivative -= pow($this->D * $a, 2) * $pStar * $Q * $pStar;

            }

 

            // Newton-Raphson update

            if (abs($secondDerivative) < 0.0001) {

                // Use gradient ascent if Hessian is near zero

                $thetaChange = 0.5 * ($firstDerivative > 0 ? 1 : -1);

            } else {

                $thetaChange = -$firstDerivative / $secondDerivative;

            }

 

            // Limit step size to prevent overshooting

            $maxStepSize = 0.5;

            if (abs($thetaChange) > $maxStepSize) {

                $thetaChange = $maxStepSize * ($thetaChange > 0 ? 1 : -1);

            }

 

            $theta = $theta + $thetaChange;

 

            // Constrain theta during iteration

            $theta = max(-3.0, min(3.0, $theta));

 

            // Check convergence

            if (abs($thetaChange) < $tolerance) {

                break;

            }

        }

 

        // Final constraint to reasonable range (-3 to 3)

        $theta = max(-3.0, min(3.0, $theta));

 

        return round($theta, 4);

    }

 

    /**

     * Select next best item to administer

     * Uses Maximum Information criterion with randomization for ties

     *

     * @param float $currentTheta Current ability estimate

     * @param array $availableItems Array of items with ['itemID', 'a', 'b', 'c']

     * @return array|null Selected item with maximum information

     */

    public function selectNextItem($currentTheta, $availableItems) {

        if (empty($availableItems)) {

            return null;

        }

 

        // Calculate information for all items

        $itemsWithInfo = [];

        foreach ($availableItems as $item) {

            $information = $this->calculateInformation(

                $currentTheta,

                $item['a'],

                $item['b'],

                $item['c'] ?? 0.25

            );

            $itemsWithInfo[] = [

                'item' => $item,

                'information' => $information

            ];

        }

 

        // Sort by information (descending)

        usort($itemsWithInfo, function($a, $b) {

            return $b['information'] <=> $a['information'];

        });

 

        // Get top items with similar information (within 5%)

        $maxInfo = $itemsWithInfo[0]['information'];

        $threshold = $maxInfo * 0.95;

 

        $topItems = array_filter($itemsWithInfo, function($item) use ($threshold) {

            return $item['information'] >= $threshold;

        });

 

        // Randomly select from top items to add variety

        $topItems = array_values($topItems);

        $randomIndex = array_rand($topItems);

 

        return $topItems[$randomIndex]['item'];

    }

 

    /**

     * Calculate expected score at a given ability level

     *

     * @param float $theta Person ability

     * @param array $items Array of items

     * @return float Expected total score

     */

    public function calculateExpectedScore($theta, $items) {

        $expectedScore = 0;

 

        foreach ($items as $item) {

            $probability = $this->calculateProbability(

                $theta,

                $item['a'],

                $item['b'],

                $item['c'] ?? 0.25

            );

            $expectedScore += $probability;

        }

 

        return $expectedScore;

    }

 

    /**

     * Calculate Standard Error of Measurement

     * Indicates precision of ability estimate

     *

     * @param float $theta Person ability

     * @param array $items Array of administered items

     * @return float Standard error

     */

    public function calculateSEM($theta, $items) {

        if (empty($items)) {

            return 999;

        }

 

        $totalInformation = 0;

 

        foreach ($items as $item) {

            $information = $this->calculateInformation(

                $theta,

                $item['a'],

                $item['b'],

                $item['c'] ?? 0.25

            );

            $totalInformation += $information;

        }

 

        if ($totalInformation <= 0) return 999;

 

        $sem = 1 / sqrt($totalInformation);

        return round($sem, 4);

    }

 

    /**

     * Classify ability level into categories

     *

     * @param float $theta Ability estimate

     * @return string Category (Below Basic, Basic, Proficient, Advanced)

     */

    public function classifyAbility($theta) {

        if ($theta < -1.0) {

            return "Below Basic";

        } elseif ($theta < 0.5) {

            return "Basic";

        } elseif ($theta < 1.5) {

            return "Proficient";

        } else {

            return "Advanced";

        }

    }

 

    /**

     * Get recommended difficulty range for next items

     *

     * @param float $theta Current ability

     * @return array ['min' => float, 'max' => float]

     */

    public function getRecommendedDifficultyRange($theta) {

        return [

            'min' => $theta - 0.5,

            'max' => $theta + 0.5

        ];

    }

 

    /**

     * Calculate reliability coefficient

     *

     * @param array $items Array of items with IRT parameters

     * @param float $theta Ability level

     * @return float Reliability (0-1)

     */

    public function calculateReliability($items, $theta) {

        $n = count($items);

        if ($n < 2) return 0;

 

        $totalVariance = 0;

 

        foreach ($items as $item) {

            $P = $this->calculateProbability($theta, $item['a'], $item['b'], $item['c'] ?? 0.25);

            $itemVariance = $P * (1 - $P);

            $totalVariance += $itemVariance;

        }

 

        $sem = $this->calculateSEM($theta, $items);

        $errorVariance = pow($sem, 2);

        $trueVariance = max(0, $totalVariance - $errorVariance);

 

        if ($totalVariance == 0) return 0;

 

        $reliability = $trueVariance / $totalVariance;

        return max(0, min(1, $reliability));

    }

 

    /**

     * Check if assessment should stop (CAT termination criteria)

     *

     * @param int $itemsAnswered Number of items answered

     * @param float $sem Current standard error

     * @param int $minItems Minimum items required

     * @param int $maxItems Maximum items allowed

     * @param float $targetSEM Target precision

     * @return array ['shouldStop' => bool, 'reason' => string]

     */

    public function checkStoppingCriteria($itemsAnswered, $sem, $minItems = 10, $maxItems = 20, $targetSEM = 0.3) {

        // Maximum items reached

        if ($itemsAnswered >= $maxItems) {

            return [

                'shouldStop' => true,

                'reason' => 'Maximum items reached'

            ];

        }

 

        // Minimum items not yet reached

        if ($itemsAnswered < $minItems) {

            return [

                'shouldStop' => false,

                'reason' => 'Minimum items not yet reached'

            ];

        }

 

        // Target precision achieved

        if ($sem <= $targetSEM) {

            return [

                'shouldStop' => true,

                'reason' => 'Target precision achieved'

            ];

        }

 

        return [

            'shouldStop' => false,

            'reason' => 'Continue assessment'

        ];

    }

}

 

// Example usage for testing (CLI only)

if (php_sapi_name() === 'cli') {

    $irt = new ItemResponseTheory();

 

    echo "Testing IRT Implementation\n";

    echo "=========================\n\n";

 

    // Test probability calculation

    $theta = 0.5;

    $a = 1.5;

    $b = 0.0;

    $c = 0.25;

 

    $prob = $irt->calculateProbability($theta, $a, $b, $c);

    echo "Probability (θ=0.5, a=1.5, b=0, c=0.25): " . round($prob, 4) . "\n";

    echo "Expected: ~0.80 (high ability relative to difficulty)\n\n";

 

    $info = $irt->calculateInformation($theta, $a, $b, $c);

    echo "Item information: " . round($info, 4) . "\n\n";

 

    // Test ability estimation with mixed responses

    $responses = [

        ['isCorrect' => true, 'a' => 1.5, 'b' => -1.0, 'c' => 0.25],  // Easy, correct

        ['isCorrect' => true, 'a' => 1.3, 'b' => -0.5, 'c' => 0.25],  // Easy, correct

        ['isCorrect' => true, 'a' => 1.4, 'b' => 0.0, 'c' => 0.25],   // Medium, correct

        ['isCorrect' => false, 'a' => 1.6, 'b' => 0.5, 'c' => 0.25],  // Medium-hard, wrong

        ['isCorrect' => true, 'a' => 1.5, 'b' => 0.3, 'c' => 0.25],   // Medium, correct

        ['isCorrect' => false, 'a' => 1.8, 'b' => 1.0, 'c' => 0.25],  // Hard, wrong

    ];

 

    $estimatedTheta = $irt->estimateAbility($responses, 0.0);

    echo "Estimated ability (θ): " . $estimatedTheta . "\n";

    echo "Ability classification: " . $irt->classifyAbility($estimatedTheta) . "\n";

 

    $sem = $irt->calculateSEM($estimatedTheta, $responses);

    echo "Standard error of measurement: " . $sem . "\n\n";

 

    // Test stopping criteria

    $stop = $irt->checkStoppingCriteria(15, $sem, 10, 20, 0.3);

    echo "Should stop: " . ($stop['shouldStop'] ? 'Yes' : 'No') . "\n";

    echo "Reason: " . $stop['reason'] . "\n";

}

 

?>