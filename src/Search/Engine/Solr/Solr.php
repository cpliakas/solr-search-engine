<?php

/**
 * Solr search service for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Engine\Solr;

use Search\Framework\Event\IndexDocumentEvent;
use Search\Framework\Event\SearchEngineEvent;
use Search\Framework\CollectionAbstract;
use Search\Framework\DateNormalizer;
use Search\Framework\IndexDocument;
use Search\Framework\Indexer;
use Search\Framework\SchemaField;
use Search\Framework\SearchEngineAbstract;
use Search\Framework\SearchEvents;
use Solarium\Client;

/**
 * Provides a Solr search service to the Search Framework library by integrating
 * with the Solarium project.
 */
class Solr extends SearchEngineAbstract
{

    protected static $_configBasename = 'solr';

    /**
     * The Solarium client interacting with the server.
     *
     * @var Client
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
     * Implements SearchEngineAbstract::init().
     *
     * Instantiates the Solarium client.
     */
    public function init(array $endpoints, array $options)
    {
        // Don't allow the "endpoint" option to be passed at runtime.
        $options['endpoint'] = array();

        // @see http://wiki.solarium-project.org/index.php/V3:Basic_usage
        foreach ($endpoints as $endpoint) {
            $id = $endpoint->getId();

            $endpoint_host = $endpoint->getHost();
            $scheme = parse_url($endpoint_host, PHP_URL_SCHEME);
            $host = parse_url($endpoint_host, PHP_URL_HOST);
            $path = rtrim((string) parse_url($endpoint_host, PHP_URL_PATH), '/');
            $path .= $endpoint->getPath();

            $options['endpoint'][$id] = array(
                'scheme' => $scheme,
                'host' => $host,
                'path' => $path,
                'port' => $endpoint->getPort(),
            );
        }

        $this->_client = new Client($options);

        $this->attachNormalizer(SchemaField::TYPE_DATE, new DateNormalizer());
    }

    /**
     * Overrides SearchEngineAbstract::getSubscribedEvents().
     */
    public static function getSubscribedEvents()
    {
        return array(
            SearchEvents::SEARCH_ENGINE_PRE_INDEX => array('preIndex'),
            SearchEvents::DOCUMENT_POST_INDEX => array('postIndexDocument'),
            SearchEvents::SEARCH_ENGINE_POST_INDEX => array('postIndex'),
        );
    }

    /**
     * Sets the Client object.
     *
     * @param Client $client
     *   The Solarium client.
     *
     * @return SolrSearchService
     */
    public function setClient(Client $client)
    {
        $this->_client = $client;
        return $this;
    }

    /**
     * Returns the Client object.
     *
     * @return Client
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
     * Overrides SearchEngineAbstract::getDocument().
     *
     * Returns a Solr specific search index document object.
     *
     * @return SolrIndexDocument
     */
    public function newDocument(Indexer $indexer)
    {
        return new SolrIndexDocument($indexer);
    }

    /**
     * Overrides SearchEngineAbstract::getField().
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
     * Implements Search::Framework::SearchEngineAbstract::createIndex().
     *
     * Solr indexes cannot be created from the client application, so this
     * method is no-op.
     */
    public function createIndex(Indexer $indexer, array $options = array()) {}

    /**
     * Listener for the SearchEvents::SEARCH_ENGINE_PRE_INDEX event.
     *
     * Initializes the array of native document objects, instantiates the update
     * request handler.
     *
     * @param SearchCollectionEvent $event
     */
    public function preIndex(SearchEngineEvent $event)
    {
        $this->_documents = array();
        $this->_update = $this->_client->createUpdate();
    }

    /**
     * Implements SearchEngineAbstract::indexDocument().
     *
     * @param CollectionAbstract $collection
     * @param SolrIndexDocument $document
     */
    public function indexDocument(CollectionAbstract $collection, IndexDocument $document)
    {
        $solarium_document = $this->_update->createDocument();

        if (null !== ($boost = $document->getBoost())) {
            $solarium_document->setBoost($boost);
        }

        foreach ($document as $field_id => $normalized_value) {
            $field = $document->getField($field_id);

            $name = $field->getName();
            $solarium_document->$name = $normalized_value;

            if (null !== ($boost = $field->getBoost())) {
                $solarium_document->setFieldBoost($name, $boost);
            }
        }

        $this->_documents[] = $solarium_document;
    }

    /**
     * Listener for the SearchEvents::DOCUMENT_POST_INDEX event.
     *
     * Issues a commit every n number of documents.
     *
     * @param SearchDocumentEvent $event
     */
    public function postIndexDocument(IndexDocumentEvent $event)
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
     * Listener for the SearchEvents::SEARCH_ENGINE_POST_INDEX event.
     *
     * Commits any remaining documents, unsets the documents and request handler
     * since they are no longer needed.
     *
     * @param SearchCollectionEvent $event
     */
    public function postIndex(SearchEngineEvent $event)
    {
        if ($this->_documents) {
            $this->_update->addDocuments($this->_documents);
        }

        $this->_update->addCommit();
        $this->_client->update($this->_update);

        unset($this->_documents, $this->_update);
    }

    /**
     * Implements Search::Framework::SearchEngineAbstract::search().
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
     * Implements Search::Framework::SearchEngineAbstract::delete().
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
