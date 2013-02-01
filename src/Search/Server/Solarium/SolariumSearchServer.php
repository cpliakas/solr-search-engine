<?php

/**
 * Search Framework
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Server\Solarium;

use Search\Framework\Event\SearchDocumentEvent;
use Search\Framework\Event\SearchCollectionEvent;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchEvents;
use Search\Framework\SearchServerAbstract;
use Search\Framework\SearchIndexDocument;
use Solarium\Client as SolariumClient;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrates the Solarium library with the Search Framework.
 */
class SolariumSearchServer extends SearchServerAbstract implements EventSubscriberInterface
{
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
     * Constructs a SolariumSearchServer object.
     *
     * @param array|SolariumClient $options
     *   The populated Solarium client object, or an array of configuration
     *   options used to instantiate a new client object.
     * @param EventDispatcher|null $dispatcher
     *   Optionally pass a dispatcher object that was instantiated elsewhere in
     *   the application. This is useful in cases where a global dispatcher is
     *   being used.
     *
     * @throws InvalidArgumentException
     */
    public function __construct($options, $dispatcher = null)
    {
        if ($options instanceof SolariumClient) {
            $this->_client = $options;
        } else {
            $this->_client = new SolariumClient($options);
        }

        if ($dispatcher instanceof EventDispatcher) {
            $this->setDispatcher($dispatcher);
        }

        $this->getDispatcher()->addSubscriber($this);
    }

    /**
     * Implements EventSubscriberInterface::getSubscribedEvents().
     */
    public static function getSubscribedEvents()
    {
        return array(
            SearchEvents::COLLECTION_PRE_INDEX => array('preIndexCollection'),
            SearchEvents::DOCUMENT_POST_INDEX => array('postIndexDocument'),
            SearchEvents::COLLECTION_POST_INDEX => array('postIndexCollection'),
        );
    }

    /**
     * Sets the SolariumClient object.
     *
     * @param SolariumClient $client
     *   The Solarium client.
     *
     * @return SolariumSearchServer
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
     * @return SolariumSearchServer
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
     * Overrides Search::Server::SearchServerAbstract::getDocument().
     *
     * Returns a Solarium specific search index document object.
     *
     * @return SolariumIndexDocument
     */
    public function newDocument()
    {
        return new SolariumIndexDocument($this);
    }

    /**
     * Overrides Search::Server::SearchServerAbstract::getField().
     *
     * Returns a Solarium specific search index field object.
     *
     * @return SolariumIndexField
     */
    public function newField($id, $value, $name = null)
    {
        return new SolariumIndexField($id, $value, $name);
    }

    /**
     * Implements Search::Server::SearchServerAbstract::createIndex().
     *
     * We cannot create Solr indexes from the client application.
     */
    public function createIndex($name, array $options = array()) {}

    /**
     * Listener for the SearchEvents::COLLECTION_PRE_INDEX event.
     *
     * Initializes the array of native document objects, instantiates the update
     * request handler.
     *
     * @param SearchCollectionEvent $event
     */
    public function preIndexCollection(SearchCollectionEvent $event)
    {
        $this->_documents = array();
        $this->_update = $this->_client->createUpdate();
    }

    /**
     * Implements Search::Server::SearchServerAbstract::indexDocument().
     *
     * @param SearchCollectionAbstract $collection
     * @param SolariumIndexDocument $document
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
     * Issues a commit every 20 documents.
     *
     * @param SearchDocumentEvent $event
     */
    public function postIndexDocument(SearchDocumentEvent $event)
    {
        if ($this->_batchSize) {
            $commit = !(count($this->_documents) % $this->_batchSize);
            if ($commit) {
                var_dump('Hi');
                $this->_update->addDocuments($this->_documents);
                $this->_client->update($this->_update);
                $this->_documents = array();
            }
        }
    }

    /**
     * Listener for the SearchEvents::COLLECTION_POST_INDEX event.
     *
     * Commits any remaining documents, unsets the documents and request handler
     * since they are no longer needed.
     *
     * @param SearchCollectionEvent $event
     */
    public function postIndexCollection(SearchCollectionEvent $event)
    {
        if ($this->_documents) {
            $this->_update->addDocuments($this->_documents);
        }

        $this->_update->addCommit();
        $this->_client->update($this->_update);

        unset($this->_documents, $this->_update);
    }

    /**
     * Implements Search::Server::SearchServerAbstract::search().
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
     * Implements Search::Server::SearchServerAbstract::delete().
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
