<?php

declare(strict_types=1);

namespace MachineLearning\Regression;

trait RegressionHelper
{
    private function calculate_average(array $arr): float
    {
        if (count($arr) === 0) {
            return 0;
        }
        return array_sum($arr) / count($arr);
    }

    private function calculate_median(array $arr): float
    {
        $count = count($arr);
        if ($count === 0) {
            return 0;
        }
        sort($arr);
        $middleval = (int) floor(($count - 1) / 2);
        if ($count % 2) {
            $median = $arr[$middleval];
        } else {
            $low = $arr[$middleval];
            $high = $arr[$middleval + 1];
            $median = (($low + $high) / 2);
        }
        return (float) $median;
    }
}

/**
 * Class LeastSquares
 * Linear model that uses least squares method to approximate solution.
 */
class LeastSquares
{
    private $xCoords = [];
    private $yCoords = [];
    private $yDifferences = [];
    private $cumulativeSum = [];
    private $slope;
    private $intercept;
    private $rSquared;
    private $coordinateCount = 0;
    private $xy = [];

    public function train(array $xCoords, array $yCoords): void
    {
        $this->resetCalculatedValues();
        $this->appendData($xCoords, $yCoords);
        $this->compute();
    }

    private function appendData(array $xCoords, array $yCoords): void
    {
        $this->xCoords = array_merge($this->xCoords, $xCoords);
        $this->yCoords = array_merge($this->yCoords, $yCoords);
        $this->countCoordinates();
    }

    private function resetCalculatedValues(): void
    {
        $this->slope = null;
        $this->intercept = null;
        $this->rSquared = null;
        $this->yDifferences = [];
        $this->cumulativeSum = [];
        $this->xy = [];
    }

    private function clearData(): void
    {
        $this->xCoords = [];
        $this->yCoords = [];
        $this->coordinateCount = 0;
    }

    public function reset(): void
    {
        $this->resetCalculatedValues();
        $this->clearData();
    }

    public function getSlope(): float
    {
        return (float) $this->slope;
    }

    public function getIntercept(): float
    {
        return (float) $this->intercept;
    }

    public function getRSquared(): float
    {
        return (float) $this->rSquared;
    }

    private function countCoordinates(): int
    {
        $this->coordinateCount = count($this->xCoords);
        $yCount = count($this->yCoords);

        if ($this->coordinateCount != $yCount) {
            return 0;
        }
        return $this->coordinateCount;
    }

    private function compute(): void
    {
        $x_sum = array_sum($this->xCoords);
        $y_sum = array_sum($this->yCoords);

        $xx_sum = 0;
        $xy_sum = 0;
        $yy_sum = 0;

        for ($i = 0; $i < $this->coordinateCount; $i++) {
            $xy_sum += ($this->xCoords[$i] * $this->yCoords[$i]);
            $xx_sum += ($this->xCoords[$i] * $this->xCoords[$i]);
            $yy_sum += ($this->yCoords[$i] * $this->yCoords[$i]);
        }

        $divisor = ($this->coordinateCount * $xx_sum) - ($x_sum * $x_sum);
        if ($divisor == 0) {
            $this->slope = 0;
        } else {
            $this->slope = (($this->coordinateCount * $xy_sum) - ($x_sum * $y_sum)) / $divisor;
        }

        $this->intercept = ($y_sum - ($this->slope * $x_sum)) / $this->coordinateCount;

        $r_divisor = ($this->coordinateCount * $xx_sum - $x_sum * $x_sum) * ($this->coordinateCount * $yy_sum - $y_sum * $y_sum);
        if ($r_divisor <= 0) {
            $this->rSquared = 0;
        } else {
            $this->rSquared = pow(($this->coordinateCount * $xy_sum - $x_sum * $y_sum) / sqrt($r_divisor), 2);
        }
    }

    public function predictY(float $x): float
    {
        return $this->getIntercept() + ($x * $this->getSlope());
    }
}
