<?php

/**
 * Search Framework
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Server\Solarium;

use Search\Framework\SearchIndexField;

/**
 * Models a field in the source data being indexed.
 *
 * This object adds Solr specific properties, such as field boosting.
 */
class SolariumIndexField extends SearchIndexField
{
    /**
     * The field level boost set for this document.
     */
    protected $_boost;

    /**
     * Sets the field level boost.
     *
     * @param float $boost
     *   The boost factor applied to the field.
     *
     * @return SolariumIndexDocument
     */
    public function setBoost($boost)
    {
        $this->_boost = $boost;
        return $this;
    }

    /**
     * Returns the field level boost.
     *
     * @return float
     */
    public function getBoost()
    {
        return $this->_boost;
    }
}
