-- ===========================================
-- ANIMEVERSE DATABASE SCHEMA
-- Run this in your Supabase SQL Editor
-- ===========================================

-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ===========================================
-- USERS TABLE
-- ===========================================
CREATE TABLE IF NOT EXISTS users (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  provider_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  email TEXT,
  name TEXT,
  password_hash TEXT, -- For credentials-based auth
  avatar TEXT,
  banner TEXT,
  bio TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  -- Settings
  settings JSONB DEFAULT '{}',

  -- Stats
  total_watch_time INTEGER DEFAULT 0,
  anime_completed INTEGER DEFAULT 0,
  episodes_watched INTEGER DEFAULT 0,

  UNIQUE(provider_id, provider)
);

-- ===========================================
-- WATCHLIST TABLE
-- ===========================================
CREATE TYPE watchlist_status AS ENUM (
  'watching',
  'completed',
  'on_hold',
  'dropped',
  'plan_to_watch'
);

CREATE TABLE IF NOT EXISTS watchlist (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,
  anime_id INTEGER NOT NULL,

  -- Anime metadata (cached)
  anime_title TEXT,
  anime_image TEXT,
  anime_total_episodes INTEGER,

  -- User progress
  status watchlist_status DEFAULT 'plan_to_watch',
  current_episode INTEGER DEFAULT 0,
  score DECIMAL(3,1),

  -- Timestamps
  started_at TIMESTAMP WITH TIME ZONE,
  completed_at TIMESTAMP WITH TIME ZONE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  -- Notes
  notes TEXT,
  is_favorite BOOLEAN DEFAULT FALSE,
  is_rewatching BOOLEAN DEFAULT FALSE,
  rewatch_count INTEGER DEFAULT 0,

  UNIQUE(user_id, anime_id)
);

-- ===========================================
-- WATCH HISTORY TABLE
-- ===========================================
CREATE TABLE IF NOT EXISTS watch_history (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,
  anime_id INTEGER NOT NULL,
  episode_id TEXT NOT NULL,
  episode_number INTEGER NOT NULL,

  -- Progress
  watch_time INTEGER DEFAULT 0, -- in seconds
  duration INTEGER, -- total duration in seconds
  progress DECIMAL(5,2) DEFAULT 0, -- percentage watched

  -- Timestamps
  watched_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  -- Metadata
  anime_title TEXT,
  episode_title TEXT,
  thumbnail TEXT
);

-- ===========================================
-- FAVORITES TABLE
-- ===========================================
CREATE TABLE IF NOT EXISTS favorites (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,
  anime_id INTEGER NOT NULL,

  -- Metadata
  anime_title TEXT,
  anime_image TEXT,

  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  UNIQUE(user_id, anime_id)
);

-- ===========================================
-- USER LISTS (Custom Lists)
-- ===========================================
CREATE TABLE IF NOT EXISTS user_lists (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  description TEXT,
  is_public BOOLEAN DEFAULT FALSE,
  cover_image TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS user_list_items (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  list_id UUID REFERENCES user_lists(id) ON DELETE CASCADE,
  anime_id INTEGER NOT NULL,
  anime_title TEXT,
  anime_image TEXT,
  position INTEGER DEFAULT 0,
  notes TEXT,
  added_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  UNIQUE(list_id, anime_id)
);

-- ===========================================
-- REVIEWS TABLE
-- ===========================================
CREATE TABLE IF NOT EXISTS reviews (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,
  anime_id INTEGER NOT NULL,

  -- Review content
  title TEXT,
  content TEXT NOT NULL,
  score DECIMAL(3,1),

  -- Reactions
  likes_count INTEGER DEFAULT 0,

  -- Flags
  contains_spoilers BOOLEAN DEFAULT FALSE,
  is_recommended BOOLEAN DEFAULT TRUE,

  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  UNIQUE(user_id, anime_id)
);

-- ===========================================
-- COMMENTS TABLE
-- ===========================================
CREATE TABLE IF NOT EXISTS comments (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,

  -- Polymorphic association
  commentable_type TEXT NOT NULL, -- 'anime', 'episode', 'review'
  commentable_id TEXT NOT NULL,

  -- Reply support
  parent_id UUID REFERENCES comments(id) ON DELETE CASCADE,

  -- Content
  content TEXT NOT NULL,

  -- Reactions
  likes_count INTEGER DEFAULT 0,

  -- Flags
  contains_spoilers BOOLEAN DEFAULT FALSE,
  is_edited BOOLEAN DEFAULT FALSE,

  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- ===========================================
-- NOTIFICATIONS TABLE
-- ===========================================
CREATE TYPE notification_type AS ENUM (
  'new_episode',
  'anime_airing',
  'comment_reply',
  'like',
  'follow',
  'recommendation',
  'system'
);

CREATE TABLE IF NOT EXISTS notifications (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE,

  type notification_type NOT NULL,
  title TEXT NOT NULL,
  message TEXT,

  -- Related entity
  related_type TEXT, -- 'anime', 'episode', 'comment', 'user'
  related_id TEXT,

  -- Status
  is_read BOOLEAN DEFAULT FALSE,

  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- ===========================================
-- FOLLOWS TABLE (User follows)
-- ===========================================
CREATE TABLE IF NOT EXISTS follows (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  follower_id UUID REFERENCES users(id) ON DELETE CASCADE,
  following_id UUID REFERENCES users(id) ON DELETE CASCADE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

  UNIQUE(follower_id, following_id)
);

-- ===========================================
-- USER SETTINGS TABLE
-- ===========================================
CREATE TABLE IF NOT EXISTS user_settings (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES users(id) ON DELETE CASCADE UNIQUE,

  -- Theme settings
  theme_id TEXT DEFAULT 'cyberpunk-cyan',
  layout_id TEXT DEFAULT 'default',

  -- Player settings
  preferred_quality TEXT DEFAULT 'auto',
  preferred_audio TEXT DEFAULT 'sub', -- 'sub' or 'dub'
  autoplay_next BOOLEAN DEFAULT TRUE,
  skip_intro BOOLEAN DEFAULT TRUE,
  skip_outro BOOLEAN DEFAULT TRUE,

  -- Notification settings
  notify_new_episodes BOOLEAN DEFAULT TRUE,
  notify_recommendations BOOLEAN DEFAULT TRUE,
  notify_comments BOOLEAN DEFAULT TRUE,
  email_notifications BOOLEAN DEFAULT FALSE,

  -- Privacy settings
  public_profile BOOLEAN DEFAULT TRUE,
  show_watch_activity BOOLEAN DEFAULT TRUE,
  show_favorites BOOLEAN DEFAULT TRUE,

  -- Display settings
  spoiler_mode BOOLEAN DEFAULT TRUE,
  adult_content BOOLEAN DEFAULT FALSE,

  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- ===========================================
-- INDEXES
-- ===========================================
CREATE INDEX idx_watchlist_user ON watchlist(user_id);
CREATE INDEX idx_watchlist_anime ON watchlist(anime_id);
CREATE INDEX idx_watchlist_status ON watchlist(status);
CREATE INDEX idx_watch_history_user ON watch_history(user_id);
CREATE INDEX idx_watch_history_anime ON watch_history(anime_id);
CREATE INDEX idx_favorites_user ON favorites(user_id);
CREATE INDEX idx_comments_commentable ON comments(commentable_type, commentable_id);
CREATE INDEX idx_notifications_user ON notifications(user_id);
CREATE INDEX idx_notifications_unread ON notifications(user_id, is_read) WHERE is_read = FALSE;

-- ===========================================
-- ROW LEVEL SECURITY
-- ===========================================
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE watchlist ENABLE ROW LEVEL SECURITY;
ALTER TABLE watch_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE favorites ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_lists ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_list_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE comments ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE follows ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_settings ENABLE ROW LEVEL SECURITY;

-- ===========================================
-- FUNCTIONS
-- ===========================================

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ language 'plpgsql';

-- Apply trigger to tables
CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_watchlist_updated_at BEFORE UPDATE ON watchlist
  FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_user_lists_updated_at BEFORE UPDATE ON user_lists
  FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_reviews_updated_at BEFORE UPDATE ON reviews
  FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_comments_updated_at BEFORE UPDATE ON comments
  FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_user_settings_updated_at BEFORE UPDATE ON user_settings
  FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
