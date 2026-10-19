import { defineConfig } from 'vitest/config'

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: [
            'tests/js/**/*.test.js',
        ],
        setupFiles: ['tests/js/setup.js'],
        clearMocks: true,
        restoreMocks: true,
        unstubGlobals: true,

        coverage: {
            provider: 'v8',

            reporter: [
                'text',
                'html',
            ],

            reportsDirectory: 'var/coverage-js',

            include: [
                'assets/**/*.js',
            ],

            exclude: [
                'assets/js/tinymce.min.js',
                'assets/js/themes/**',
                'assets/js/icons/**',
                'assets/js/skins/**',
                'assets/vendor/**',
            ],
        },
    },
})