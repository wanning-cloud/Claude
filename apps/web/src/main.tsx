import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider, Navigate } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError } from './api.ts';
import { Layout } from './components/Layout.tsx';
import { PageLoading } from './components/States.tsx';
import './styles.css';

const Overview = lazy(() => import('./pages/Overview.tsx'));
const Episodes = lazy(() => import('./pages/Episodes.tsx'));
const EpisodeDetail = lazy(() => import('./pages/EpisodeDetail.tsx'));
const Platform = lazy(() => import('./pages/Platform.tsx'));
const Comments = lazy(() => import('./pages/Comments.tsx'));
const Automation = lazy(() => import('./pages/Automation.tsx'));
const Social = lazy(() => import('./pages/Social.tsx'));
const NotFound = lazy(() => import('./pages/NotFound.tsx'));

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      refetchOnWindowFocus: true,
      retry: (count, error) => !(error instanceof ApiError && error.status > 0 && error.status < 500) && count < 2,
    },
  },
});

const page = (el: React.ReactNode) => <Suspense fallback={<PageLoading />}>{el}</Suspense>;

const router = createBrowserRouter(
  [
    {
      element: <Layout />,
      children: [
        { index: true, element: page(<Overview />) },
        { path: 'folgen', element: page(<Episodes />) },
        { path: 'folgen/:id', element: page(<EpisodeDetail />) },
        { path: 'portale', element: <Navigate to="/portale/youtube" replace /> },
        { path: 'portale/:platform', element: page(<Platform />) },
        { path: 'social', element: page(<Social />) },
        { path: 'kommentare', element: page(<Comments />) },
        { path: 'kommentare/:id', element: page(<Comments />) },
        { path: 'automatik', element: page(<Automation />) },
        { path: '*', element: page(<NotFound />) },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL.replace(/\/$/, '') },
);

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
);
