import type { NextConfig } from "next";

const apacheBackend =
  process.env.APACHE_BACKEND_URL ?? "http://127.0.0.1/buse-hub/backend";

/**
 * Required for phone testing at http://192.168.x.x:3000 — without this, Next.js
 * returns 403 on /_next/* assets and buttons never work (page scrolls only).
 */
const allowedDevOrigins = (
  process.env.NEXT_ALLOWED_DEV_ORIGINS ?? "192.168.0.145,192.168.122.1"
)
  .split(",")
  .map((h) => h.trim())
  .filter(Boolean);

const nextConfig: NextConfig = {
  allowedDevOrigins,
  images: {
    remotePatterns: [
      {
        protocol: 'http',
        hostname: 'localhost',
        port: '',
        pathname: '/buse-hub/uploads/**',
      },
      {
        protocol: 'http',
        hostname: '127.0.0.1',
        port: '',
        pathname: '/buse-hub/backend/uploads/**',
      },
    ],
  },

  async rewrites() {
    return [
      {
        source: "/backend-php/:path*",
        destination: `${apacheBackend}/:path*`,
      },
      // Proxy uploaded images so they load correctly on LAN / phone access
      // (PHP returns relative paths like /buse-hub/backend/uploads/...)
      {
        source: "/buse-hub/backend/uploads/:path*",
        destination: `${apacheBackend}/uploads/:path*`,
      },
      {
        source: "/api/:path*",
        destination: `${apacheBackend}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
