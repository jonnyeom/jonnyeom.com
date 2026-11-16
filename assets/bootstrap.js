import { startStimulusApp } from '@symfony/reprise/stimulus';
import { registerReactControllerComponents } from '@symfony/ux-react';

const reactControllers = import.meta.glob('./react/controllers/**/*.{jsx,tsx}', { eager: true });

// Adapt Vite's glob imports to the context expected by Symfony UX React.
const reactContext = (path) => reactControllers[`./react/controllers/${path.slice(2)}`];
reactContext.keys = () => Object.keys(reactControllers).map((path) => path.replace('./react/controllers/', './'));
registerReactControllerComponents(reactContext);

startStimulusApp();
