import { createRouter, createWebHistory } from 'vue-router';
import QueueWork from '../views/QueueWork.vue';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'queue-work',
      component: QueueWork,
    },
  ],
});

export default router;
