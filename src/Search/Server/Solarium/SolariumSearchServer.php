<?php

/**
 * Search Framework
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Server\Solarium;

use Search\Framework\Event\SearchDocumentEvent;
use Search\Framework\Event\SearchCollectionEvent;
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
     * The documents queued for indexing.
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
     * Instantiates a SolariumClient object or sets it.
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
     *   The solarium client.
     *
     * @return SolariumSearchServer
     */
    public function setClient(SolariumClient $client)
    {
        return $this->_client;
    }

    /**
     * Returns the Client object.
     *
     * @return SolariumClient
     */
    public function getClient()
    {
        return $this->_client;
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
     * @param SolariumIndexDocument $document
     */
    public function indexDocument(SearchIndexDocument $document)
    {
        $solrarium_document = $this->_update->createDocument();

        if (null !== ($boost = $document->getBoost())) {
            $solrarium_document->setBoost($boost);
        }

        foreach ($document as $field_id => $normalized_value) {
            $field = $document->getField($field_id);

            $name = $field->getName();
            $solrarium_document->$name = $normalized_value;

            if (null !== ($boost = $field->getBoost())) {
                $solrarium_document->setFieldBoost($name, $boost);
            }
        }
        $this->_documents[] = $solrarium_document;
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
        $commit = 0 == (count($this->_documents) % 20);
        if ($commit) {
            $this->_update->addDocuments($this->_documents);
            $this->_client->update($this->_update);
            $this->_documents = array();
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
     * Implements Search::Server::SearchServerAbstract::delete().
     *
     * @param SolariumIndexDocument $document
     */
    public function delete()
    {
        $update = $this->_client->createUpdate();
        $update->addDeleteQuery('*:*');
        $update->addCommit();
        $this->_client->update($update);
    }

    /**
     * Pass all other method calls directly to the Solarium client.
     */
    public function __call($method, $args)
    {
        return call_user_func_array(array($this->_client, $method), $args);
    }
}
