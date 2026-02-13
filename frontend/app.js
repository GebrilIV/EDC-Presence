import { createApp, reactive } from 'https://unpkg.com/vue@3/dist/vue.esm-browser.prod.js';

createApp({
  setup() {
    const state = reactive({
      currentTab: 'presence',
    });

    function setTab(tab) {
      state.currentTab = tab;
    }

    return {
      state,
      setTab,
    };
  },
}).mount('#app');
