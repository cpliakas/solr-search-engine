<?php

/**
 * Solr search service for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Engine\Solr;

use Search\Framework\IndexDocument;

/**
 * Models a document containing the source data being indexed.
 *
 * This object adds Solr specific properties, such as field boosting.
 */
class SolrIndexDocument extends IndexDocument
{
    /**
     * The document level boost set for this document.
     */
    protected $_boost;

    /**
     * Sets the document level boost.
     *
     * @param float $boost
     *   The boost factor applied to the field.
     *
     * @return SolrIndexDocument
     */
    public function setBoost($boost)
    {
        $this->_boost = $boost;
        return $this;
    }

    /**
     * Returns the document level boost.
     *
     * @return float
     */
    public function getBoost()
    {
        return $this->_boost;
    }

    /**
     * Returns the field level boost.
     *
     * @param string $id
     *   The unique identifier of the field.
     *
     * @return float
     */
    public function getFieldBoost($id)
    {
        return $this->getField($id)->getBoost();
    }
}
