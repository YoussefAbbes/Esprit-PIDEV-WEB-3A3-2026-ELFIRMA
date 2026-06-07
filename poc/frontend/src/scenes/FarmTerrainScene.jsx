import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { useRef, useMemo } from 'react';
import * as THREE from 'three';
import { EffectComposer, Vignette } from '@react-three/postprocessing';
import { Stars, Float, useScroll, ScrollControls } from '@react-three/drei';

function Terrain() {
  const geo = useMemo(() => {
    const g = new THREE.PlaneGeometry(200, 200, 100, 100);
    g.rotateX(-Math.PI / 2);
    const pos = g.attributes.position;
    for (let i = 0; i < pos.count; i++) {
      const x = pos.getX(i), z = pos.getZ(i);
      const h =
        Math.sin(x * 0.04) * 4 +
        Math.cos(z * 0.06) * 3 +
        Math.sin((x + z) * 0.08) * 1.8 +
        Math.cos(x * 0.18) * 0.6;
      pos.setY(i, h);
    }
    g.computeVertexNormals();
    return g;
  }, []);

  return (
    <mesh geometry={geo} receiveShadow>
      <meshLambertMaterial color="#1f4a1d" />
    </mesh>
  );
}

function Pollen({ count = 1500 }) {
  const ref = useRef();
  const speeds = useMemo(() => new Float32Array(count).map(() => 0.004 + Math.random() * 0.012), [count]);
  const offsets = useMemo(() => new Float32Array(count).map(() => Math.random() * Math.PI * 2), [count]);

  const positions = useMemo(() => {
    const arr = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
      arr[i * 3]     = (Math.random() - 0.5) * 160;
      arr[i * 3 + 1] = Math.random() * 40;
      arr[i * 3 + 2] = (Math.random() - 0.5) * 160;
    }
    return arr;
  }, [count]);

  useFrame((state) => {
    const t = state.clock.elapsedTime;
    const arr = ref.current.geometry.attributes.position.array;
    for (let i = 0; i < count; i++) {
      arr[i * 3 + 1] += speeds[i];
      arr[i * 3]     += Math.sin(t + offsets[i]) * 0.005;
      if (arr[i * 3 + 1] > 40) arr[i * 3 + 1] = 0;
    }
    ref.current.geometry.attributes.position.needsUpdate = true;
  });

  return (
    <points ref={ref}>
      <bufferGeometry>
        <bufferAttribute attach="attributes-position" count={count} array={positions} itemSize={3} />
      </bufferGeometry>
      <pointsMaterial color="#c9a227" size={0.22} transparent opacity={0.8} sizeAttenuation />
    </points>
  );
}

function CropTokens() {
  return (
    <Float floatIntensity={1.5} rotationIntensity={0.4} speed={1.2}>
      <group position={[0, 8, -15]}>
        {[...Array(6)].map((_, i) => {
          const a = (i / 6) * Math.PI * 2;
          return (
            <mesh key={i} position={[Math.cos(a) * 8, Math.sin(i * 1.3) * 1.5, Math.sin(a) * 8]}>
              <icosahedronGeometry args={[0.6, 0]} />
              <meshStandardMaterial
                color={i % 2 ? '#c9a227' : '#4caf50'}
                emissive={i % 2 ? '#c9a227' : '#4caf50'}
                emissiveIntensity={0.5}
                wireframe
              />
            </mesh>
          );
        })}
      </group>
    </Float>
  );
}

function ScrollCamera() {
  const { camera } = useThree();
  const scroll = useScroll();

  useFrame(() => {
    const p = scroll.offset;
    camera.position.x = Math.sin(p * Math.PI * 2) * 12;
    camera.position.y = THREE.MathUtils.lerp(10, 22, p) - Math.sin(p * Math.PI) * 4;
    camera.position.z = THREE.MathUtils.lerp(55, -25, p);
    camera.lookAt(0, 2 + p * 6, 0);
  });
  return null;
}

function Atmosphere() {
  return (
    <>
      <fog attach="fog" args={['#07130a', 25, 130]} />
      <color attach="background" args={['#050a05']} />
      <ambientLight intensity={0.7} color="#1a3050" />
      <directionalLight
        position={[40, 60, 30]}
        intensity={1.6}
        color="#d4a843"
      />
      <directionalLight position={[-30, 20, -20]} intensity={0.5} color="#4a7c5a" />
      <Stars radius={120} depth={50} count={1200} factor={3} fade speed={0.5} />
    </>
  );
}

export default function FarmTerrainScene({ scrollPages = 6 }) {
  return (
    <Canvas
      className="canvas-fixed"
      dpr={[1, 1.5]}
      camera={{ position: [0, 10, 55], fov: 55, near: 0.1, far: 250 }}
      gl={{ antialias: true, powerPreference: 'high-performance' }}
    >
      <Atmosphere />
      <Terrain />
      <Pollen />
      <CropTokens />
      <ScrollControls pages={scrollPages} damping={0.18}>
        <ScrollCamera />
      </ScrollControls>
      <EffectComposer multisampling={0}>
        <Vignette eskil={false} offset={0.15} darkness={0.7} />
      </EffectComposer>
    </Canvas>
  );
}
