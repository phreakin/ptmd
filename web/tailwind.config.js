/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './web/**/*.php',
    './web/assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        'ptmd-black':  '#0B0C10',
        'ptmd-white':  '#F5F5F3',
        'ptmd-navy':   '#1C2A39',
        'ptmd-gray':   '#2F3A40',
        'ptmd-red':    '#C1121F',
        'ptmd-yellow': '#FFD60A',
        'ptmd-teal':   '#2EC4B6',
        'ptmd-gold':   '#BFA181',
        'ptmd-purple': '#6A0DAD',
        'ptmd-blue':   '#2563EB',
        'ptmd-green':  '#2E7D32',
        'ptmd-orange': '#F97316',
        'ptmd-pink':   '#D63384',
      },
      fontFamily: {
        sans:    ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', 'Inter', 'system-ui', 'sans-serif'],
      },
      fontSize: {
        'fluid-sm': ['clamp(0.8125rem, 0.78rem + 0.2vw, 0.875rem)', { lineHeight: '1.4' }],
        'fluid-md': ['clamp(0.9375rem, 0.9rem + 0.25vw, 1rem)',     { lineHeight: '1.6' }],
        'fluid-lg': ['clamp(1.125rem, 1rem + 0.6vw, 1.375rem)',      { lineHeight: '1.4' }],
        'fluid-xl': ['clamp(1.5rem, 1.25rem + 1.2vw, 2rem)',         { lineHeight: '1.2' }],
        'fluid-2xl':['clamp(1.875rem, 1.5rem + 1.8vw, 2.75rem)',    { lineHeight: '1.15' }],
        'fluid-3xl':['clamp(2.25rem, 1.75rem + 2.5vw, 3.5rem)',     { lineHeight: '1.1' }],
        'fluid-4xl':['clamp(2.75rem, 2rem + 3.5vw, 4.5rem)',        { lineHeight: '1.05' }],
      },
      backdropBlur: {
        glass: '20px',
      },
      backgroundImage: {
        'ptmd-hero':   'radial-gradient(ellipse 60% 80% at 70% -10%, rgba(46,196,182,0.12) 0%, transparent 60%)',
        'ptmd-mesh':   'radial-gradient(ellipse 50% 60% at -10% 80%, rgba(255,214,10,0.08) 0%, transparent 50%)',
      },
    },
  },
  plugins: [],
};
