<?php

/**
 * Elasticservice search service for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Service\Solr;

use Search\Framework\SearchNormalizerInterface;

/**
 *
 */
class SolrDateNormalizer implements SearchNormalizerInterface
{
    /**
     * Implements SearchNormalizerInterface::normalize().
     *
     * Normalizes date values to a Solr-friendly format.
     */
    public function normalize($value)
    {
        if ($value) {
            if (is_int($value) || ctype_digit($value)) {
                $timestamp = $value;
            } elseif (!$timestamp = strtotime($value)) {
                return $value;
            }
            return date('Y-m-d\TH:i:s\Z', $timestamp);
        }
        return $value;
    }
}
