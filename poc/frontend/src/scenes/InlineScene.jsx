import { Canvas, useFrame } from '@react-three/fiber';
import { useRef, useMemo } from 'react';
import { Float, Sparkles } from '@react-three/drei';
import LazyMount from '../ui/LazyMount.jsx';

// A reusable inline 3D thumbnail. Lightweight — no postprocessing,
// dpr capped at 1.25, mounted only when in the viewport. This keeps
// us well under the browser WebGL context limit.

const VARIANTS = {
  crop:      { color: '#4caf50', accent: '#c9a227', geom: 'crystal', rotSpeed: 0.4 },
  livestock: { color: '#c9a227', accent: '#e8c35a', geom: 'orb',     rotSpeed: 0.3 },
  equipment: { color: '#6366f1', accent: '#818cf8', geom: 'torus',   rotSpeed: 0.5 },
  ai:        { color: '#10b981', accent: '#34d399', geom: 'network', rotSpeed: 0.25 },
};

function CenterPiece({ variant }) {
  const ref = useRef();
  const v = VARIANTS[variant] || VARIANTS.crop;
  useFrame(({ clock }) => {
    if (!ref.current) return;
    ref.current.rotation.x = clock.elapsedTime * v.rotSpeed * 0.6;
    ref.current.rotation.y = clock.elapsedTime * v.rotSpeed;
  });

  const geom = useMemo(() => {
    switch (v.geom) {
      case 'orb':     return <icosahedronGeometry args={[1.3, 2]} />;
      case 'torus':   return <torusKnotGeometry args={[0.9, 0.28, 100, 16]} />;
      case 'network': return <octahedronGeometry args={[1.4, 0]} />;
      default:        return <icosahedronGeometry args={[1.3, 1]} />;
    }
  }, [v.geom]);

  return (
    <Float floatIntensity={1.4} rotationIntensity={0.5} speed={1.5}>
      <mesh ref={ref}>
        {geom}
        <meshStandardMaterial
          color={v.color}
          emissive={v.color}
          emissiveIntensity={0.55}
          metalness={0.6}
          roughness={0.25}
          wireframe={v.geom === 'network'}
        />
      </mesh>
      <mesh rotation={[Math.PI / 2.4, 0, 0]}>
        <torusGeometry args={[2.2, 0.012, 16, 100]} />
        <meshBasicMaterial color={v.accent} transparent opacity={0.45} />
      </mesh>
    </Float>
  );
}

function InlineCanvas({ variant }) {
  const v = VARIANTS[variant] || VARIANTS.crop;
  return (
    <Canvas
      dpr={[1, 1.25]}
      camera={{ position: [0, 0, 5], fov: 50 }}
      gl={{ alpha: true, antialias: true, powerPreference: 'low-power' }}
      style={{ width: '100%', height: '100%' }}
    >
      <ambientLight intensity={0.45} />
      <pointLight position={[3, 3, 3]} intensity={1.4} color={v.color} />
      <pointLight position={[-3, -2, -2]} intensity={0.8} color={v.accent} />
      <CenterPiece variant={variant} />
      <Sparkles count={50} scale={6} size={2} speed={0.4} color={v.accent} opacity={0.5} />
    </Canvas>
  );
}

export default function InlineScene({ variant = 'crop' }) {
  return (
    <LazyMount rootMargin="200px">
      <InlineCanvas variant={variant} />
    </LazyMount>
  );
}
