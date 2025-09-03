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

    public function getGameMode(): ?string
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

    public function isCompetitiveMode(): bool
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

    private function generateRoomCode(): string
    {
        return strtoupper(substr(md5(uniqid()), 0, 6));
    }
}
