import './bootstrap';
import Alpine from 'alpinejs';
import { promptGame } from './play';

window.Alpine = Alpine;
Alpine.data('promptGame', promptGame);
Alpine.start();
