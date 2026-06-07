import { useRef } from 'react';
import { useInView } from 'framer-motion';

// Only mount the (heavy) children once the wrapper has scrolled into view.
// Optionally unmounts again when fully scrolled past — useful for canvases.
export default function LazyMount({ children, rootMargin = '20%', keepMounted = true, style }) {
  const ref = useRef();
  const inView = useInView(ref, { margin: rootMargin, once: keepMounted });
  return (
    <div ref={ref} style={{ width: '100%', height: '100%', ...style }}>
      {inView ? children : null}
    </div>
  );
}
