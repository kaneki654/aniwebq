'use client';

import { useState, useEffect, useCallback } from 'react';
import type { Anime, Episode, StreamingSource } from '@/types';

// Generic fetch hook
function useFetch<T>(url: string | null, options?: RequestInit) {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!url) return;

    const fetchData = async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(url, options);
        const result = await response.json();
        if (result.success) {
          setData(result.data);
        } else {
          setError(result.error || 'Failed to fetch data');
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'An error occurred');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [url, options]);

  return { data, loading, error };
}

// Trending anime hook
export function useTrending(page = 1, perPage = 20) {
  const { data, loading, error } = useFetch<Anime[]>(
    `/api/anime/trending?page=${page}&perPage=${perPage}`
  );

  return {
    trending: data || [],
    loading,
    error,
  };
}

// Popular anime hook
export function usePopular(page = 1, perPage = 20) {
  const { data, loading, error } = useFetch<Anime[]>(
    `/api/anime/popular?page=${page}&perPage=${perPage}`
  );

  return {
    popular: data || [],
    loading,
    error,
  };
}

// Search anime hook
export function useSearch(query: string, filters?: {
  genres?: string[];
  year?: number;
  season?: string;
  format?: string;
  status?: string;
  sort?: string;
  page?: number;
  perPage?: number;
}) {
  const [results, setResults] = useState<Anime[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    totalPages: 1,
    totalItems: 0,
    hasNextPage: false,
  });

  const search = useCallback(async () => {
    if (!query && !filters?.genres?.length) return;

    setLoading(true);
    setError(null);

    try {
      const params = new URLSearchParams();
      if (query) params.set('q', query);
      if (filters?.genres?.length) params.set('genres', filters.genres.join(','));
      if (filters?.year) params.set('year', String(filters.year));
      if (filters?.season) params.set('season', filters.season);
      if (filters?.format) params.set('format', filters.format);
      if (filters?.status) params.set('status', filters.status);
      if (filters?.sort) params.set('sort', filters.sort);
      params.set('page', String(filters?.page || 1));
      params.set('perPage', String(filters?.perPage || 20));

      const response = await fetch(`/api/anime/search?${params}`);
      const result = await response.json();

      if (result.success) {
        setResults(result.data);
        setPagination(result.pagination);
      } else {
        setError(result.error);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Search failed');
    } finally {
      setLoading(false);
    }
  }, [query, filters]);

  useEffect(() => {
    const debounceTimer = setTimeout(search, 300);
    return () => clearTimeout(debounceTimer);
  }, [search]);

  return {
    results,
    loading,
    error,
    pagination,
    search,
  };
}

// Single anime details hook
export function useAnimeDetails(id: number | null) {
  const { data, loading, error } = useFetch<Anime>(
    id ? `/api/anime/${id}` : null
  );

  return {
    anime: data,
    loading,
    error,
  };
}

// Anime episodes hook
export function useEpisodes(animeId: number | null, provider = 'aniwatch') {
  const [episodes, setEpisodes] = useState<Episode[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [streamingId, setStreamingId] = useState<string | null>(null);
  const [hasDub, setHasDub] = useState(false);

  useEffect(() => {
    if (!animeId) return;

    const fetchEpisodes = async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(
          `/api/anime/${animeId}/episodes?provider=${provider}`
        );
        const result = await response.json();

        if (result.success) {
          setEpisodes(result.data.episodes || []);
          setStreamingId(result.data.streamingId);
          setHasDub(result.data.hasDub || false);
        } else {
          setError(result.error);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch episodes');
      } finally {
        setLoading(false);
      }
    };

    fetchEpisodes();
  }, [animeId, provider]);

  return {
    episodes,
    loading,
    error,
    streamingId,
    hasDub,
  };
}

// Streaming sources hook
export function useStreamingSources(
  episodeId: string | null,
  provider = 'aniwatch',
  category = 'sub'
) {
  const [sources, setSources] = useState<StreamingSource[]>([]);
  const [subtitles, setSubtitles] = useState<any[]>([]);
  const [headers, setHeaders] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [intro, setIntro] = useState<{ start: number; end: number } | null>(null);
  const [outro, setOutro] = useState<{ start: number; end: number } | null>(null);

  useEffect(() => {
    if (!episodeId) return;

    const fetchSources = async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(
          `/api/watch/${encodeURIComponent(episodeId)}?provider=${provider}&category=${category}`
        );
        const result = await response.json();

        if (result.success) {
          setSources(result.data.sources || []);
          setSubtitles(result.data.subtitles || []);
          setHeaders(result.data.headers || {});
          setIntro(result.data.intro || null);
          setOutro(result.data.outro || null);
        } else {
          setError(result.error);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch sources');
      } finally {
        setLoading(false);
      }
    };

    fetchSources();
  }, [episodeId, provider, category]);

  return {
    sources,
    subtitles,
    headers,
    loading,
    error,
    intro,
    outro,
  };
}

// Schedule hook
export function useSchedule(page = 1, perPage = 50) {
  const [schedule, setSchedule] = useState<Record<string, any[]>>({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchSchedule = async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(`/api/schedule?page=${page}&perPage=${perPage}`);
        const result = await response.json();

        if (result.success) {
          setSchedule(result.data);
        } else {
          setError(result.error);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch schedule');
      } finally {
        setLoading(false);
      }
    };

    fetchSchedule();
  }, [page, perPage]);

  return {
    schedule,
    loading,
    error,
  };
}

// Infinite scroll hook
export function useInfiniteAnime(
  type: 'trending' | 'popular' | 'search',
  query?: string,
  filters?: any
) {
  const [anime, setAnime] = useState<Anime[]>([]);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [hasMore, setHasMore] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadMore = useCallback(async () => {
    if (loading || !hasMore) return;

    setLoading(true);
    setError(null);

    try {
      let url: string;
      if (type === 'search') {
        const params = new URLSearchParams({
          q: query || '',
          page: String(page),
          perPage: '20',
          ...filters,
        });
        url = `/api/anime/search?${params}`;
      } else {
        url = `/api/anime/${type}?page=${page}&perPage=20`;
      }

      const response = await fetch(url);
      const result = await response.json();

      if (result.success) {
        setAnime(prev => [...prev, ...result.data]);
        setHasMore(result.pagination?.hasNextPage ?? false);
        setPage(prev => prev + 1);
      } else {
        setError(result.error);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load more');
    } finally {
      setLoading(false);
    }
  }, [type, query, filters, page, loading, hasMore]);

  const reset = useCallback(() => {
    setAnime([]);
    setPage(1);
    setHasMore(true);
    setError(null);
  }, []);

  return {
    anime,
    loading,
    hasMore,
    error,
    loadMore,
    reset,
  };
}

// Homepage data hook - fetches all data needed for homepage
export function useHomepageData() {
  const [data, setData] = useState<{
    trending: Anime[];
    popular: Anime[];
    topRated: Anime[];
    newReleases: Anime[];
  }>({
    trending: [],
    popular: [],
    topRated: [],
    newReleases: [],
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [retryCount, setRetryCount] = useState(0);

  useEffect(() => {
    const fetchAll = async () => {
      setLoading(true);
      setError(null);

      try {
        // Fetch with individual error handling
        const trendingRes = await fetch('/api/anime/trending?perPage=12');
        const popularRes = await fetch('/api/anime/popular?perPage=12');

        let trending = { success: false, data: [] as Anime[] };
        let popular = { success: false, data: [] as Anime[] };

        try {
          trending = await trendingRes.json();
        } catch (e) {
          console.error('Failed to parse trending response');
        }

        try {
          popular = await popularRes.json();
        } catch (e) {
          console.error('Failed to parse popular response');
        }

        const trendingData = trending.success ? trending.data : [];
        const popularData = popular.success ? popular.data : [];

        // Only retry if BOTH failed and we haven't exceeded retry limit
        if (trendingData.length === 0 && popularData.length === 0 && retryCount < 2) {
          setTimeout(() => setRetryCount(prev => prev + 1), 1000);
          return;
        }

        setData({
          trending: trendingData,
          popular: popularData,
          topRated: popularData.slice(0, 6),
          newReleases: trendingData.slice(0, 6),
        });

        if (trendingData.length === 0 && popularData.length === 0) {
          setError('Failed to load anime data. Please refresh the page.');
        }
      } catch (err) {
        if (retryCount < 2) {
          setTimeout(() => setRetryCount(prev => prev + 1), 1000);
        } else {
          setError(err instanceof Error ? err.message : 'Failed to load homepage data');
        }
      } finally {
        setLoading(false);
      }
    };

    fetchAll();
  }, [retryCount]);

  return {
    ...data,
    loading,
    error,
  };
}
