<?php

namespace App\Entity;

use App\Repository\RoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoomRepository::class)]
class Room
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private ?string $content = null;

    #[ORM\Column(length: 255)]
    private ?string $url = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\Column(length: 20)]
    private ?string $gameMode = 'competition';

    #[ORM\OneToMany(targetEntity: GameSession::class, mappedBy: 'room')]
    private Collection $gameSessions;

    #[ORM\Column(type: 'json')]
    private array $wordsToFind = [];

    #[ORM\Column(type: 'json')]
    private array $hints = [];

    #[ORM\Column(type: 'json')]
    private array $globalFoundWords = [];

    #[ORM\Column]
    private ?bool $isGameCompleted = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $winnerId = null;

    #[ORM\Column]
    private ?int $gameNumber = 1;

    #[ORM\Column(nullable: true)]
    private ?int $newGameInitiatorId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $newGameRequestedAt = null;

    #[ORM\Column]
    private ?bool $isNewGameInProgress = false;

    public function __construct()
    {
        $this->gameSessions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->code = $this->generateRoomCode();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    /**
     * @return Collection<int, GameSession>
     */
    public function getGameSessions(): Collection
    {
        return $this->gameSessions;
    }

    public function addGameSession(GameSession $gameSession): static
    {
        if (!$this->gameSessions->contains($gameSession)) {
            $this->gameSessions->add($gameSession);
            $gameSession->setRoom($this);
        }

        return $this;
    }

    public function removeGameSession(GameSession $gameSession): static
    {
        if ($this->gameSessions->removeElement($gameSession)) {
            // set the owning side to null (unless already changed)
            if ($gameSession->getRoom() === $this) {
                $gameSession->setRoom(null);
            }
        }

        return $this;
    }

    public function getWordsToFind(): array
    {
        return $this->wordsToFind;
    }

    public function setWordsToFind(array $wordsToFind): static
    {
        $this->wordsToFind = $wordsToFind;
        return $this;
    }

    public function getHints(): array
    {
        return $this->hints;
    }

    public function setHints(array $hints): static
    {
        $this->hints = $hints;
        return $this;
    }

    public function getGameMode(): string
    {
        return $this->gameMode;
    }

    public function setGameMode(string $gameMode): static
    {
        $this->gameMode = $gameMode;
        return $this;
    }

    public function isCooperativeMode(): bool
    {
        return $this->gameMode === 'cooperation';
    }

    public function isCompetitionMode(): bool
    {
        return $this->gameMode === 'competition';
    }

    public function getGlobalFoundWords(): array
    {
        return $this->globalFoundWords;
    }

    public function setGlobalFoundWords(array $globalFoundWords): static
    {
        $this->globalFoundWords = $globalFoundWords;
        return $this;
    }

    public function addGlobalFoundWord(string $word): static
    {
        if (!in_array($word, $this->globalFoundWords)) {
            $this->globalFoundWords[] = $word;
        }
        return $this;
    }

    public function isGameCompleted(): bool
    {
        return $this->isGameCompleted ?? false;
    }

    public function setIsGameCompleted(bool $isGameCompleted): static
    {
        $this->isGameCompleted = $isGameCompleted;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getWinnerId(): ?int
    {
        return $this->winnerId;
    }

    public function setWinnerId(?int $winnerId): static
    {
        $this->winnerId = $winnerId;
        return $this;
    }

    public function getGameNumber(): int
    {
        return $this->gameNumber ?? 1;
    }

    public function setGameNumber(int $gameNumber): static
    {
        $this->gameNumber = $gameNumber;
        return $this;
    }

    public function incrementGameNumber(): static
    {
        $this->gameNumber = ($this->gameNumber ?? 1) + 1;
        return $this;
    }

    public function getNewGameInitiatorId(): ?int
    {
        return $this->newGameInitiatorId;
    }

    public function setNewGameInitiatorId(?int $newGameInitiatorId): static
    {
        $this->newGameInitiatorId = $newGameInitiatorId;
        return $this;
    }

    public function getNewGameRequestedAt(): ?\DateTimeImmutable
    {
        return $this->newGameRequestedAt;
    }

    public function setNewGameRequestedAt(?\DateTimeImmutable $newGameRequestedAt): static
    {
        $this->newGameRequestedAt = $newGameRequestedAt;
        return $this;
    }

    public function isNewGameInProgress(): bool
    {
        return $this->isNewGameInProgress ?? false;
    }

    public function setIsNewGameInProgress(bool $isNewGameInProgress): static
    {
        $this->isNewGameInProgress = $isNewGameInProgress;
        return $this;
    }

    public function canStartNewGame(): bool
    {
        // Vérifier si aucune nouvelle partie n'est en cours
        // et si la demande précédente est expirée (plus de 30 secondes)
        if ($this->isNewGameInProgress) {
            return false;
        }

        if ($this->newGameRequestedAt) {
            $now = new \DateTimeImmutable();
            $timeDiff = $now->getTimestamp() - $this->newGameRequestedAt->getTimestamp();
            return $timeDiff > 30; // 30 secondes d'expiration
        }

        return true;
    }

    public function lockForNewGame(int $initiatorId): void
    {
        $this->setIsNewGameInProgress(true);
        $this->setNewGameInitiatorId($initiatorId);
        $this->setNewGameRequestedAt(new \DateTimeImmutable());
    }

    public function unlockNewGame(): void
    {
        $this->setIsNewGameInProgress(false);
        $this->setNewGameInitiatorId(null);
        $this->setNewGameRequestedAt(null);
    }

    public function resetForNewGame(string $newTitle, string $newContent, string $newUrl, array $newWordsToFind): void
    {
        // Réinitialiser pour une nouvelle partie
        $this->setTitle($newTitle);
        $this->setContent($newContent);
        $this->setUrl($newUrl);
        $this->setWordsToFind($newWordsToFind);
        $this->setGlobalFoundWords([]);
        $this->setIsGameCompleted(false);
        $this->setCompletedAt(null);
        $this->setWinnerId(null);
        $this->incrementGameNumber();
        $this->unlockNewGame();
    }

    private function generateRoomCode(): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }
}
