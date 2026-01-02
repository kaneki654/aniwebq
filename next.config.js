/** @type {import('next').NextConfig} */
const nextConfig = {
  swcMinify: false, // Disable for low-RAM system to prevent SIGTERM
  compress: true,
  productionBrowserSourceMaps: false,
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 's4.anilist.co',
      },
      {
        protocol: 'https',
        hostname: 'cdn.myanimelist.net',
      },
      {
        protocol: 'https',
        hostname: 'media.kitsu.io',
      },
      {
        protocol: 'https',
        hostname: 'artworks.thetvdb.com',
      },
      {
        protocol: 'https',
        hostname: 'img.youtube.com',
      },
      {
        protocol: 'https',
        hostname: 'cdn.noitatnemucod.net',
      },
      {
        protocol: 'https',
        hostname: 'mgstatics.xyz',
      },
      {
        protocol: 'https',
        hostname: 'via.placeholder.com',
      },
    ],
    unoptimized: false,
  },
  typescript: {
    ignoreBuildErrors: true,
  },
  eslint: {
    ignoreDuringBuilds: true,
  },
  experimental: {
    optimizePackageImports: [
      '@chakra-ui/react',
      'framer-motion',
      'react-icons/fi',
      'react-icons/hi',
      'react-icons/ri',
      'lucide-react',
      'swiper',
      'axios',
    ],
    esmExternals: 'loose',
  },
};

module.exports = nextConfig;
