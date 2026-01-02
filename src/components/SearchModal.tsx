'use client';

import { useState, useEffect, useRef, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  Box,
  Modal,
  ModalOverlay,
  ModalContent,
  ModalBody,
  Input,
  InputGroup,
  InputLeftElement,
  InputRightElement,
  VStack,
  HStack,
  Text,
  Icon,
  Image,
  Badge,
  Spinner,
  Kbd,
  Divider,
  Button,
} from '@chakra-ui/react';
import {
  FiSearch,
  FiX,
  FiTrendingUp,
  FiClock,
  FiStar,
  FiArrowRight,
} from 'react-icons/fi';
import gsap from 'gsap';
import { useThemeStore } from '@/store/useThemeStore';
import { useSearch } from '@/hooks/useAnime';
import type { Anime } from '@/types';

interface SearchModalProps {
  isOpen: boolean;
  onClose: () => void;
}

// Recent searches (mock - would come from localStorage)
const recentSearches = ['Jujutsu Kaisen', 'Frieren', 'Solo Leveling', 'One Piece'];

// Trending searches (mock)
const trendingSearches = ['Attack on Titan', 'Demon Slayer', 'My Hero Academia', 'Spy x Family'];

// Search Result Item
function SearchResultItem({
  anime,
  currentTheme,
  onClick,
}: {
  anime: Anime;
  currentTheme: any;
  onClick: () => void;
}) {
  return (
    <HStack
      p={3}
      borderRadius="lg"
      cursor="pointer"
      transition="all 0.2s ease"
      _hover={{
        bg: 'surface.hover',
        transform: 'translateX(4px)',
      }}
      onClick={onClick}
    >
      <Image
        src={anime.coverImage?.medium || anime.coverImage?.large || ''}
        alt={anime.title?.english || anime.title?.romaji || ''}
        w="50px"
        h="70px"
        objectFit="cover"
        borderRadius="md"
        fallbackSrc="https://via.placeholder.com/50x70?text=?"
      />
      <VStack flex={1} align="stretch" spacing={1}>
        <Text fontWeight="bold" fontSize="sm" noOfLines={1}>
          {anime.title?.english || anime.title?.romaji}
        </Text>
        <HStack fontSize="xs" color="text.secondary" spacing={2}>
          {anime.averageScore && (
            <HStack spacing={1}>
              <Icon as={FiStar} color="yellow.400" />
              <Text>{(anime.averageScore / 10).toFixed(1)}</Text>
            </HStack>
          )}
          {anime.format && <Badge size="sm">{anime.format}</Badge>}
          {anime.seasonYear && <Text>{anime.seasonYear}</Text>}
        </HStack>
        <HStack spacing={1}>
          {anime.genres?.slice(0, 2).map((genre: string) => (
            <Badge key={genre} size="sm" variant="subtle" fontSize="2xs">
              {genre}
            </Badge>
          ))}
        </HStack>
      </VStack>
      <Icon as={FiArrowRight} color="text.muted" />
    </HStack>
  );
}

export default function SearchModal({ isOpen, onClose }: SearchModalProps) {
  const router = useRouter();
  const currentTheme = useThemeStore((state) => state.currentTheme);

  const [query, setQuery] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);
  const resultsRef = useRef<HTMLDivElement>(null);

  const { results, loading, error } = useSearch(query);

  // Focus input on open
  useEffect(() => {
    if (isOpen && inputRef.current) {
      setTimeout(() => inputRef.current?.focus(), 100);
    }
  }, [isOpen]);

  // GSAP animation for results
  useEffect(() => {
    if (!loading && results.length > 0 && resultsRef.current) {
      gsap.from(resultsRef.current.children, {
        y: 10,
        opacity: 0,
        duration: 0.3,
        stagger: 0.05,
        ease: 'power2.out',
      });
    }
  }, [loading, results]);

  // Handle result click
  const handleResultClick = (anime: Anime) => {
    router.push(`/anime/${anime.id}`);
    onClose();
    setQuery('');
  };

  // Handle search submit
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (query.trim()) {
      router.push(`/browse?q=${encodeURIComponent(query)}`);
      onClose();
      setQuery('');
    }
  };

  // Handle suggestion click
  const handleSuggestionClick = (suggestion: string) => {
    setQuery(suggestion);
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="xl" motionPreset="slideInTop">
      <ModalOverlay backdropFilter="blur(20px)" bg="blackAlpha.800" />
      <ModalContent
        bg="background.primary"
        border="1px solid"
        borderColor="border"
        borderRadius="2xl"
        overflow="hidden"
        mx={4}
        mt={{ base: 4, md: '10vh' }}
      >
        <ModalBody p={0}>
          {/* Search Input */}
          <form onSubmit={handleSubmit}>
            <InputGroup size="lg">
              <InputLeftElement h="60px" pl={4}>
                {loading ? (
                  <Spinner size="sm" color={currentTheme.colors.primary} />
                ) : (
                  <Icon as={FiSearch} color="text.muted" boxSize={5} />
                )}
              </InputLeftElement>
              <Input
                ref={inputRef}
                placeholder="Search anime, characters, studios..."
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                h="60px"
                pl={12}
                pr={20}
                border="none"
                borderBottom="1px solid"
                borderColor="border"
                borderRadius="none"
                fontSize="lg"
                _focus={{ boxShadow: 'none' }}
                _placeholder={{ color: 'text.muted' }}
              />
              <InputRightElement h="60px" pr={4}>
                {query ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setQuery('')}
                    p={1}
                  >
                    <Icon as={FiX} />
                  </Button>
                ) : (
                  <Kbd>ESC</Kbd>
                )}
              </InputRightElement>
            </InputGroup>
          </form>

          {/* Results / Suggestions */}
          <Box maxH="60vh" overflowY="auto" p={4}>
            {query.length > 0 ? (
              // Search Results
              <>
                {results.length > 0 ? (
                  <VStack ref={resultsRef} align="stretch" spacing={1}>
                    <Text fontSize="xs" color="text.muted" mb={2} px={3}>
                      {results.length} results found
                    </Text>
                    {results.slice(0, 8).map((anime) => (
                      <SearchResultItem
                        key={anime.id}
                        anime={anime}
                        currentTheme={currentTheme}
                        onClick={() => handleResultClick(anime)}
                      />
                    ))}
                    {results.length > 8 && (
                      <Button
                        variant="ghost"
                        size="sm"
                        mt={2}
                        onClick={handleSubmit}
                        rightIcon={<FiArrowRight />}
                      >
                        View all {results.length} results
                      </Button>
                    )}
                  </VStack>
                ) : !loading ? (
                  <VStack py={8} spacing={3}>
                    <Icon as={FiSearch} boxSize={10} color="text.muted" />
                    <Text color="text.secondary">No results found for &quot;{query}&quot;</Text>
                    <Text fontSize="sm" color="text.muted">
                      Try a different search term
                    </Text>
                  </VStack>
                ) : null}
              </>
            ) : (
              // Suggestions
              <VStack align="stretch" spacing={6}>
                {/* Recent Searches */}
                <Box>
                  <HStack mb={3}>
                    <Icon as={FiClock} color="text.muted" />
                    <Text fontSize="sm" fontWeight="medium" color="text.secondary">
                      Recent Searches
                    </Text>
                  </HStack>
                  <HStack flexWrap="wrap" gap={2}>
                    {recentSearches.map((search) => (
                      <Badge
                        key={search}
                        px={3}
                        py={1}
                        borderRadius="full"
                        cursor="pointer"
                        transition="all 0.2s ease"
                        _hover={{
                          bg: currentTheme.colors.primary,
                          color: currentTheme.colors.background,
                        }}
                        onClick={() => handleSuggestionClick(search)}
                      >
                        {search}
                      </Badge>
                    ))}
                  </HStack>
                </Box>

                <Divider borderColor="border" />

                {/* Trending Searches */}
                <Box>
                  <HStack mb={3}>
                    <Icon as={FiTrendingUp} color={currentTheme.colors.primary} />
                    <Text fontSize="sm" fontWeight="medium" color="text.secondary">
                      Trending Searches
                    </Text>
                  </HStack>
                  <VStack align="stretch" spacing={1}>
                    {trendingSearches.map((search, index) => (
                      <HStack
                        key={search}
                        p={2}
                        borderRadius="md"
                        cursor="pointer"
                        transition="all 0.2s ease"
                        _hover={{ bg: 'surface.hover' }}
                        onClick={() => handleSuggestionClick(search)}
                      >
                        <Text
                          fontWeight="bold"
                          color={index < 3 ? currentTheme.colors.primary : 'text.muted'}
                          w="20px"
                        >
                          {index + 1}
                        </Text>
                        <Text>{search}</Text>
                      </HStack>
                    ))}
                  </VStack>
                </Box>

                {/* Quick Links */}
                <Box>
                  <Text fontSize="sm" fontWeight="medium" color="text.secondary" mb={3}>
                    Quick Links
                  </Text>
                  <HStack spacing={2}>
                    <Link href="/browse?genres=Action">
                      <Badge colorScheme="red" cursor="pointer">Action</Badge>
                    </Link>
                    <Link href="/browse?genres=Romance">
                      <Badge colorScheme="pink" cursor="pointer">Romance</Badge>
                    </Link>
                    <Link href="/browse?genres=Comedy">
                      <Badge colorScheme="yellow" cursor="pointer">Comedy</Badge>
                    </Link>
                    <Link href="/browse?genres=Fantasy">
                      <Badge colorScheme="purple" cursor="pointer">Fantasy</Badge>
                    </Link>
                    <Link href="/trending">
                      <Badge colorScheme="brand" cursor="pointer">Trending</Badge>
                    </Link>
                  </HStack>
                </Box>
              </VStack>
            )}
          </Box>

          {/* Footer */}
          <HStack
            justify="space-between"
            p={3}
            borderTop="1px solid"
            borderColor="border"
            fontSize="xs"
            color="text.muted"
          >
            <HStack spacing={4}>
              <HStack>
                <Kbd size="sm">↵</Kbd>
                <Text>to search</Text>
              </HStack>
              <HStack>
                <Kbd size="sm">↑↓</Kbd>
                <Text>to navigate</Text>
              </HStack>
            </HStack>
            <HStack>
              <Kbd size="sm">ESC</Kbd>
              <Text>to close</Text>
            </HStack>
          </HStack>
        </ModalBody>
      </ModalContent>
    </Modal>
  );
}
