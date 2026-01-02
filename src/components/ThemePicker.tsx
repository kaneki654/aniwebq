'use client';

import { useState, useMemo } from 'react';
import {
  Box,
  Modal,
  ModalOverlay,
  ModalContent,
  ModalHeader,
  ModalBody,
  ModalCloseButton,
  SimpleGrid,
  VStack,
  HStack,
  Text,
  Input,
  InputGroup,
  InputLeftElement,
  Icon,
  Button,
  Badge,
  Tooltip,
  Tabs,
  TabList,
  Tab,
  TabPanels,
  TabPanel,
  IconButton,
  useToast,
  Flex,
  Wrap,
  WrapItem,
} from '@chakra-ui/react';
import {
  FiSearch,
  FiStar,
  FiHeart,
  FiShuffle,
  FiCheck,
  FiSun,
  FiMoon,
  FiDroplet,
  FiZap,
  FiFeather,
} from 'react-icons/fi';
import { useThemeStore } from '@/store/useThemeStore';
import { allThemes as themes, type Theme } from '@/styles/themes';
import type { Theme as ThemeType } from '@/types';

// Theme categories
const CATEGORIES = [
  { id: 'all', label: 'All', icon: FiZap },
  { id: 'dark', label: 'Dark', icon: FiMoon },
  { id: 'light', label: 'Light', icon: FiSun },
  { id: 'anime', label: 'Anime', icon: FiFeather },
  { id: 'gradient', label: 'Gradient', icon: FiDroplet },
  { id: 'seasonal', label: 'Seasonal', icon: FiStar },
];

// Theme Card Component
function ThemeCard({
  theme,
  isActive,
  isFavorite,
  onSelect,
  onToggleFavorite,
}: {
  theme: Theme;
  isActive: boolean;
  isFavorite: boolean;
  onSelect: () => void;
  onToggleFavorite: () => void;
}) {
  return (
    <Box
      position="relative"
      borderRadius="xl"
      overflow="hidden"
      cursor="pointer"
      transition="all 0.3s ease"
      transform={isActive ? 'scale(1.02)' : 'scale(1)'}
      boxShadow={isActive ? `0 0 0 3px ${theme.colors.primary}, ${theme.effects.glow || '0 10px 30px rgba(0,0,0,0.3)'}` : 'md'}
      _hover={{
        transform: 'translateY(-4px) scale(1.02)',
        boxShadow: `${theme.effects.glow || '0 15px 40px rgba(0,0,0,0.4)'}`,
      }}
      onClick={onSelect}
    >
      {/* Theme Preview */}
      <Box
        h="120px"
        bg={theme.colors.background}
        position="relative"
        p={3}
      >
        {/* Navbar Preview */}
        <HStack
          bg={theme.colors.surface}
          borderRadius="md"
          px={2}
          py={1}
          spacing={2}
          mb={2}
        >
          <Box w="8px" h="8px" borderRadius="full" bg={theme.colors.primary} />
          <Box flex={1} h="6px" bg={theme.colors.primary} opacity={0.3} borderRadius="full" />
          <HStack spacing={1}>
            <Box w="6px" h="6px" borderRadius="full" bg={theme.colors.text} opacity={0.5} />
            <Box w="6px" h="6px" borderRadius="full" bg={theme.colors.text} opacity={0.5} />
          </HStack>
        </HStack>

        {/* Content Preview */}
        <HStack spacing={2}>
          <Box
            w="35px"
            h="50px"
            borderRadius="sm"
            bg={`linear-gradient(135deg, ${theme.colors.primary}, ${theme.colors.secondary})`}
          />
          <VStack align="flex-start" spacing={1} flex={1}>
            <Box h="8px" w="60%" bg={theme.colors.text} opacity={0.8} borderRadius="sm" />
            <Box h="6px" w="40%" bg={theme.colors.text} opacity={0.4} borderRadius="sm" />
            <HStack spacing={1}>
              <Box
                h="12px"
                px={2}
                bg={theme.colors.primary}
                borderRadius="sm"
                fontSize="6px"
                color={theme.colors.background}
                display="flex"
                alignItems="center"
              >
                Watch
              </Box>
            </HStack>
          </VStack>
        </HStack>

        {/* Gradient Effect */}
        {theme.effects.gradient && (
          <Box
            position="absolute"
            bottom={0}
            left={0}
            right={0}
            h="30px"
            bg={theme.effects.gradient}
            opacity={0.5}
          />
        )}
      </Box>

      {/* Theme Name */}
      <HStack
        bg={theme.colors.surface}
        px={3}
        py={2}
        justify="space-between"
      >
        <VStack align="flex-start" spacing={0}>
          <Text fontSize="sm" fontWeight="bold" color={theme.colors.text}>
            {theme.name}
          </Text>
          <Text fontSize="xs" color={theme.colors.text} opacity={0.6}>
            {theme.category}
          </Text>
        </VStack>
        <HStack spacing={1}>
          {isActive && (
            <Icon as={FiCheck} color={theme.colors.primary} boxSize={4} />
          )}
          <IconButton
            aria-label={isFavorite ? 'Remove from favorites' : 'Add to favorites'}
            icon={<FiHeart />}
            size="xs"
            variant="ghost"
            color={isFavorite ? 'red.500' : theme.colors.text}
            opacity={isFavorite ? 1 : 0.5}
            onClick={(e) => {
              e.stopPropagation();
              onToggleFavorite();
            }}
            _hover={{ opacity: 1 }}
          />
        </HStack>
      </HStack>

      {/* Active Indicator */}
      {isActive && (
        <Box
          position="absolute"
          top={2}
          right={2}
          bg={theme.colors.primary}
          color={theme.colors.background}
          borderRadius="full"
          p={1}
        >
          <Icon as={FiCheck} boxSize={3} />
        </Box>
      )}
    </Box>
  );
}

interface ThemePickerProps {
  isOpen: boolean;
  onClose: () => void;
}

export default function ThemePicker({ isOpen, onClose }: ThemePickerProps) {
  const toast = useToast();
  const {
    currentTheme,
    favoriteThemes,
    setTheme,
    setRandomTheme,
    addFavoriteTheme,
    removeFavoriteTheme,
  } = useThemeStore();

  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('all');

  // Filter themes
  const filteredThemes = useMemo(() => {
    return themes.filter((theme) => {
      const matchesSearch = theme.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        theme.category.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesCategory = selectedCategory === 'all' ||
        theme.category.toLowerCase().includes(selectedCategory.toLowerCase());
      return matchesSearch && matchesCategory;
    });
  }, [searchQuery, selectedCategory]);

  // Favorite themes
  const favoriteThemeObjects = useMemo(() => {
    return themes.filter((theme) => favoriteThemes.includes(theme.id));
  }, [favoriteThemes]);

  const handleSelectTheme = (theme: Theme) => {
    setTheme(theme.id);
    toast({
      title: `Theme Changed`,
      description: `Now using "${theme.name}" theme`,
      status: 'success',
      duration: 2000,
      isClosable: true,
    });
  };

  const handleToggleFavorite = (themeId: string) => {
    if (favoriteThemes.includes(themeId)) {
      removeFavoriteTheme(themeId);
      toast({
        title: 'Removed from favorites',
        status: 'info',
        duration: 1500,
      });
    } else {
      addFavoriteTheme(themeId);
      toast({
        title: 'Added to favorites',
        status: 'success',
        duration: 1500,
      });
    }
  };

  const handleRandomTheme = () => {
    setRandomTheme();
    toast({
      title: 'Random Theme Applied!',
      description: `Now using "${currentTheme.name}" theme`,
      status: 'info',
      duration: 2000,
    });
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="6xl" scrollBehavior="inside">
      <ModalOverlay backdropFilter="blur(10px)" />
      <ModalContent
        bg="background.primary"
        maxH="90vh"
        mx={4}
        borderRadius="2xl"
        overflow="hidden"
      >
        <ModalHeader>
          <VStack align="stretch" spacing={4}>
            <HStack justify="space-between">
              <VStack align="flex-start" spacing={0}>
                <Text fontSize="2xl" fontWeight="bold">
                  Theme Gallery
                </Text>
                <Text fontSize="sm" color="text.secondary">
                  500+ beautiful themes to customize your experience
                </Text>
              </VStack>
              <HStack>
                <Tooltip label="Random Theme">
                  <IconButton
                    aria-label="Random theme"
                    icon={<FiShuffle />}
                    onClick={handleRandomTheme}
                    colorScheme="brand"
                    variant="outline"
                  />
                </Tooltip>
              </HStack>
            </HStack>

            {/* Search */}
            <InputGroup>
              <InputLeftElement>
                <Icon as={FiSearch} color="text.muted" />
              </InputLeftElement>
              <Input
                placeholder="Search themes..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                bg="surface.primary"
                border="none"
                _focus={{ boxShadow: currentTheme.effects.glow || undefined }}
              />
            </InputGroup>
          </VStack>
        </ModalHeader>

        <ModalCloseButton />

        <ModalBody pb={6}>
          <Tabs variant="soft-rounded" colorScheme="brand">
            <TabList mb={6} flexWrap="wrap" gap={2}>
              <Tab>
                <HStack spacing={2}>
                  <Icon as={FiZap} />
                  <Text>All Themes</Text>
                  <Badge colorScheme="brand" borderRadius="full">
                    {themes.length}
                  </Badge>
                </HStack>
              </Tab>
              <Tab>
                <HStack spacing={2}>
                  <Icon as={FiHeart} />
                  <Text>Favorites</Text>
                  {favoriteThemes.length > 0 && (
                    <Badge colorScheme="red" borderRadius="full">
                      {favoriteThemes.length}
                    </Badge>
                  )}
                </HStack>
              </Tab>
            </TabList>

            <TabPanels>
              {/* All Themes Tab */}
              <TabPanel p={0}>
                {/* Category Filters */}
                <Wrap mb={6}>
                  {CATEGORIES.map((category) => (
                    <WrapItem key={category.id}>
                      <Button
                        size="sm"
                        leftIcon={<Icon as={category.icon} />}
                        variant={selectedCategory === category.id ? 'solid' : 'outline'}
                        colorScheme={selectedCategory === category.id ? 'brand' : 'gray'}
                        onClick={() => setSelectedCategory(category.id)}
                      >
                        {category.label}
                      </Button>
                    </WrapItem>
                  ))}
                </Wrap>

                {/* Themes Grid */}
                <SimpleGrid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} spacing={4}>
                  {filteredThemes.map((theme) => (
                    <ThemeCard
                      key={theme.id}
                      theme={theme}
                      isActive={currentTheme.id === theme.id}
                      isFavorite={favoriteThemes.includes(theme.id)}
                      onSelect={() => handleSelectTheme(theme)}
                      onToggleFavorite={() => handleToggleFavorite(theme.id)}
                    />
                  ))}
                </SimpleGrid>

                {filteredThemes.length === 0 && (
                  <VStack py={12} spacing={4}>
                    <Icon as={FiSearch} boxSize={12} color="text.muted" />
                    <Text color="text.secondary">No themes found</Text>
                    <Button
                      size="sm"
                      onClick={() => {
                        setSearchQuery('');
                        setSelectedCategory('all');
                      }}
                    >
                      Clear Filters
                    </Button>
                  </VStack>
                )}
              </TabPanel>

              {/* Favorites Tab */}
              <TabPanel p={0}>
                {favoriteThemeObjects.length > 0 ? (
                  <SimpleGrid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} spacing={4}>
                    {favoriteThemeObjects.map((theme) => (
                      <ThemeCard
                        key={theme.id}
                        theme={theme}
                        isActive={currentTheme.id === theme.id}
                        isFavorite={true}
                        onSelect={() => handleSelectTheme(theme)}
                        onToggleFavorite={() => handleToggleFavorite(theme.id)}
                      />
                    ))}
                  </SimpleGrid>
                ) : (
                  <VStack py={12} spacing={4}>
                    <Icon as={FiHeart} boxSize={12} color="text.muted" />
                    <Text color="text.secondary">No favorite themes yet</Text>
                    <Text fontSize="sm" color="text.muted" textAlign="center">
                      Click the heart icon on any theme to add it to your favorites
                    </Text>
                  </VStack>
                )}
              </TabPanel>
            </TabPanels>
          </Tabs>
        </ModalBody>
      </ModalContent>
    </Modal>
  );
}
