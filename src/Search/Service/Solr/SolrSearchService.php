<?php

/**
 * Solr search service for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Service\Solr;

use Search\Framework\Event\SearchDocumentEvent;
use Search\Framework\Event\SearchServiceEvent;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchEvents;
use Search\Framework\SearchServiceAbstract;
use Search\Framework\SearchIndexDocument;
use Solarium\Client as SolariumClient;

/**
 * Provides a Solr search service to the Search Framework library by integrating
 * with the Solarium project.
 */
class SolrSearchService extends SearchServiceAbstract
{

    protected static $_id = 'solr';

    /**
     * The Solarium client interacting with the server.
     *
     * @var SolariumClient
     */
    protected $_client;

    /**
     * The native document objects ready to be indexed indexing.
     *
     * @var array
     */
    protected $_documents;

    /**
     * The update request handler.
     *
     * @var \Solarium\QueryType\Update\Query\Query
     */
    protected $_update;

    /**
     * The batch size, defaults to 0 meaning there is no batch functionality.
     *
     * Issues a POST request to the Solr server after the number of documents
     * secified by this variable are processed for indexing. For large indexing
     * operations, this prevents documents from building up in memory and allows
     * the application to send smaller requests to Solr.
     *
     * @var int
     */
    protected $_batchSize = 0;

    /**
     * Overrides Search::Framework::SearchServiceAbstract::init().
     *
     * Sets the Solarium client, registers listeners.
     */
    public function init(array $endpoints, array $options)
    {
        // @see http://wiki.solarium-project.org/index.php/V3:Basic_usage
        $client_options = array('endpoint' => array());
        foreach ($endpoints as $endpoint) {
            $id = $endpoint->getId();

            $endpoint_host = $endpoint->getHost();
            $scheme = parse_url($endpoint_host, PHP_URL_SCHEME);
            $host = parse_url($endpoint_host, PHP_URL_HOST);
            $path = rtrim((string) parse_url($endpoint_host, PHP_URL_PATH), '/');
            $path .= $endpoint->getPath();

            $client_options['endpoint'][$id] = array(
                'scheme' => $scheme,
                'host' => $host,
                'path' => $path,
                'port' => $endpoint->getPort(),
            );
        }

        $this->_client = new SolariumClient($client_options);
    }

    /**
     * Overrides SearchServiceAbstract::getSubscribedEvents().
     */
    public static function getSubscribedEvents()
    {
        return array(
            SearchEvents::SERVICE_PRE_INDEX => array('preIndex'),
            SearchEvents::FIELD_NORMALIZE => array('normalizeField'),
            SearchEvents::DOCUMENT_POST_INDEX => array('postIndexDocument'),
            SearchEvents::SERVICE_POST_INDEX => array('postIndex'),
        );
    }

    /**
     * Sets the SolariumClient object.
     *
     * @param SolariumClient $client
     *   The Solarium client.
     *
     * @return SolrSearchService
     */
    public function setClient(SolariumClient $client)
    {
        $this->_client = $client;
        return $this;
    }

    /**
     * Returns the SolariumClient object.
     *
     * @return SolariumClient
     */
    public function getClient()
    {
        return $this->_client;
    }

    /**
     * Sets the number of documents processed per batch.
     *
     * @param int $batch_size
     *   The number of documents processed per batch.
     *
     * @return SolrSearchService
     */
    public function setBatchSize($batch_size)
    {
        $this->_batchSize = $batch_size;
        return $this;
    }

    /**
     * Returns the number of documents processed per batch.
     *
     * @return int
     */
    public function getBatchSize()
    {
        return $this->_batchSize;
    }

    /**
     * Overrides Search::Framework::SearchServiceAbstract::getDocument().
     *
     * Returns a Solr specific search index document object.
     *
     * @return SolrIndexDocument
     */
    public function newDocument()
    {
        return new SolrIndexDocument($this);
    }

    /**
     * Overrides Search::Framework::SearchServiceAbstract::getField().
     *
     * Returns a Solr specific search index field object.
     *
     * @return SolrIndexField
     */
    public function newField($id, $value, $name = null)
    {
        return new SolrIndexField($id, $value, $name);
    }

    /**
     * Implements Search::Framework::SearchServiceAbstract::createIndex().
     *
     * Solr indexes cannot be created from the client application, so this
     * method is no-op.
     */
    public function createIndex($name, array $options = array()) {}

    /**
     * Listener for the SearchEvents::SERVICE_PRE_INDEX event.
     *
     * Initializes the array of native document objects, instantiates the update
     * request handler.
     *
     * @param SearchCollectionEvent $event
     */
    public function preIndex(SearchServiceEvent $event)
    {
        $this->_documents = array();
        $this->_update = $this->_client->createUpdate();
    }

    /**
     * Listener for the SearchEvents::FIELD_NORMALIZE event.
     */
    public function normalizeField(SearchFieldEvent $event)
    {
        // Refer to https://github.com/cpliakas/search-framework/issues/26.
        // This is nasty, but it works. Hopefully it won't be required soon.
        $schema = $this->getSchema();
        $field = $event->getField();
        switch ($schema->getField($field->getId())->getType()) {
            case SearchSchemaField::TYPE_DATE:
                $timestamp = strtotime($field);
                $event->setValue(date('Y-m-d\TH:i:s\Z', $timestamp));
                break;
        }
    }

    /**
     * Implements SearchServiceAbstract::indexDocument().
     *
     * @param SearchCollectionAbstract $collection
     * @param SolrIndexDocument $document
     */
    public function indexDocument(SearchCollectionAbstract $collection, SearchIndexDocument $document)
    {
        $index_doc = $this->_update->createDocument();

        if (null !== ($boost = $document->getBoost())) {
            $index_doc->setBoost($boost);
        }

        foreach ($document as $field_id => $normalized_value) {
            $field = $document->getField($field_id);

            $name = $field->getName();
            $index_doc->$name = $normalized_value;

            if (null !== ($boost = $field->getBoost())) {
                $index_doc->setFieldBoost($name, $boost);
            }
        }

        $this->_documents[] = $index_doc;
    }

    /**
     * Listener for the SearchEvents::DOCUMENT_POST_INDEX event.
     *
     * Issues a commit every n number of documents.
     *
     * @param SearchDocumentEvent $event
     */
    public function postIndexDocument(SearchDocumentEvent $event)
    {
        if ($this->_batchSize) {
            $post_documents = !(count($this->_documents) % $this->_batchSize);
            if ($post_documents) {
                $this->_update->addDocuments($this->_documents);
                $this->_client->update($this->_update);
                $this->_documents = array();
            }
        }
    }

    /**
     * Listener for the SearchEvents::SERVICE_POST_INDEX event.
     *
     * Commits any remaining documents, unsets the documents and request handler
     * since they are no longer needed.
     *
     * @param SearchCollectionEvent $event
     */
    public function postIndex(SearchServiceEvent $event)
    {
        if ($this->_documents) {
            $this->_update->addDocuments($this->_documents);
        }

        $this->_update->addCommit();
        $this->_client->update($this->_update);

        unset($this->_documents, $this->_update);
    }

    /**
     * Implements Search::Framework::SearchServiceAbstract::search().
     *
     * @return \Solarium\QueryType\Select\Result\Result
     */
    public function search($keywords, array $options = array())
    {
        $query = $this->_client->createSelect();
        $query->setQuery($keywords);
        return $this->_client->select($query);
    }

    /**
     * Implements Search::Framework::SearchServiceAbstract::delete().
     *
     * @return \Solarium\QueryType\Update\Result
     */
    public function delete()
    {
        $update = $this->_client->createUpdate();
        $update->addDeleteQuery('*:*');
        $update->addCommit();
        return $this->_client->update($update);
    }
}
