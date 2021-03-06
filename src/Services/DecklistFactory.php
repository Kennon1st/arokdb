<?php

namespace App\Services;

use App\Entity\DeckInterface;
use App\Entity\Decklist;
use App\Entity\DecklistInterface;
use App\Entity\Decklistslot;
use App\Services\DeckValidationHelper;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class DecklistFactory
{
    protected EntityManagerInterface $doctrine;

    protected DeckValidationHelper $deckValidationHelper;

    protected Texts $texts;

    public function __construct(
        EntityManagerInterface $doctrine,
        DeckValidationHelper $deckValidationHelper,
        Texts $texts
    ) {
        $this->doctrine = $doctrine;
        $this->deckValidationHelper = $deckValidationHelper;
        $this->texts = $texts;
    }

    /**
     * @param DeckInterface $deck
     * @param null $name
     * @param null $descriptionMd
     * @return DecklistInterface
     * @throws Exception
     */
    public function createDecklistFromDeck(DeckInterface $deck, $name = null, $descriptionMd = null)
    {
        $lastPack = $deck->getLastPack();
        if (!$lastPack->getDateRelease() || $lastPack->getDateRelease() > new DateTime()) {
            throw new Exception("You cannot publish this deck yet, because it has unreleased cards.");
        }

        $problem = $this->deckValidationHelper->findProblem($deck);
        if ($problem) {
            throw new Exception(
                'This deck cannot be published because it is invalid: "'
                . $this->deckValidationHelper->getProblemLabel($problem)
                . '".'
            );
        }

        // all good for decklist publication

        // increasing deck version
        $deck->setMinorVersion(0);
        $deck->setMajorVersion($deck->getMajorVersion() + 1);

        if (empty($name)) {
            $name = $deck->getName();
        }
        $name = substr($name, 0, 60);

        if (empty($descriptionMd)) {
            $descriptionMd = $deck->getDescriptionMd();
        }
        $description = $this->texts->markdown($descriptionMd);

        $new_content = json_encode($deck->getSlots()->getContent());
        $new_signature = md5($new_content);

        $decklist = new Decklist();
        $decklist->setName($name);
        $decklist->setVersion($deck->getVersion());
        $decklist->setNameCanonical($this->texts->slugify($name) . '-' . $decklist->getVersion());
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($description);
        $decklist->setDateCreation(new DateTime());
        $decklist->setDateUpdate(new DateTime());
        $decklist->setSignature($new_signature);
        $decklist->setFaction($deck->getFaction());
        $decklist->setLastPack($deck->getLastPack());
        $decklist->setNbVotes(0);
        $decklist->setNbfavorites(0);
        $decklist->setNbcomments(0);
        $decklist->setUser($deck->getUser());
        foreach ($deck->getSlots() as $slot) {
            $decklistslot = new Decklistslot();
            $decklistslot->setQuantity($slot->getQuantity());
            $decklistslot->setCard($slot->getCard());
            $decklistslot->setDecklist($decklist);
            $decklist->getSlots()->add($decklistslot);
        }
        if (count($deck->getChildren())) {
            $decklist->setPrecedent($deck->getChildren()[0]);
        } else {
            if ($deck->getParent()) {
                $decklist->setPrecedent($deck->getParent());
            }
        }
        $decklist->setParent($deck);

        $deck->setMinorVersion(1);

        return $decklist;
    }
}
