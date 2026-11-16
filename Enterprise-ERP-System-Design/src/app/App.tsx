import { RouterProvider } from 'react-router';
import { router } from './routes';
import { Toaster } from './components/ui/sonner';
import { ReviewMode } from './components/ReviewMode';

function App() {
  return (
    <>
      <RouterProvider router={router} />
      <Toaster />
      <ReviewMode />
    </>
  );
}

export default App;
