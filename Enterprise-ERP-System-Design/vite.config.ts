import { defineConfig } from 'vite'
import path from 'path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'


function figmaAssetResolver() {
  return {
    name: 'figma-asset-resolver',
    resolveId(id) {
      if (id.startsWith('figma:asset/')) {
        const filename = id.replace('figma:asset/', '')
        return path.resolve(__dirname, 'src/assets', filename)
      }
    },
  }
}

// Backend: the PHP API only (the old Node demo server is retired). Set VITE_API_PROXY to point the dev server at another PHP API; the default is the live site.
const PHP = process.env.VITE_API_PROXY || 'https://erpsaudi.quadrotechs.com'

export default defineConfig({
  // the production build can live in a sub-folder (e.g. /app/) next to the old site
  base: process.env.VITE_BASE || '/',
  plugins: [
    figmaAssetResolver(),
    // The React and Tailwind plugins are both required for Make, even if
    // Tailwind is not being actively used – do not remove them
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      // Alias @ to the src directory
      '@': path.resolve(__dirname, './src'),
    },
  },

  // File types to support raw imports. Never add .css, .tsx, or .ts files to this.
  assetsInclude: ['**/*.svg', '**/*.csv'],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: PHP, changeOrigin: true, secure: true },
    },
  },
})
