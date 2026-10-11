import '../css/theme.css';
import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

// restore saved light/dark choice before first paint
document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'light');

createApp(App).use(router).mount('#app');
