'use client';

import { useEffect, useRef, useCallback } from 'react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

// Register GSAP plugins
if (typeof window !== 'undefined') {
  gsap.registerPlugin(ScrollTrigger);
}

// ============================================
// GSAP ANIMATION HOOKS
// ============================================

/**
 * Basic GSAP animation hook
 */
export const useGSAPAnimation = <T extends HTMLElement>(
  animation: (element: T, gsapInstance: typeof gsap) => gsap.core.Tween | gsap.core.Timeline,
  deps: React.DependencyList = []
) => {
  const elementRef = useRef<T>(null);
  const animationRef = useRef<gsap.core.Tween | gsap.core.Timeline | null>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    animationRef.current = animation(elementRef.current, gsap);

    return () => {
      animationRef.current?.kill();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return elementRef;
};

/**
 * Stagger animation for multiple elements
 */
export const useStaggerAnimation = (
  selector: string,
  options: gsap.TweenVars = {},
  deps: React.DependencyList = []
) => {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!containerRef.current) return;

    const elements = containerRef.current.querySelectorAll(selector);
    if (elements.length === 0) return;

    const tween = gsap.from(elements, {
      y: 50,
      opacity: 0,
      duration: 0.8,
      stagger: 0.1,
      ease: 'power3.out',
      ...options,
    });

    return () => {
      tween.kill();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return containerRef;
};

/**
 * Scroll-triggered animation
 */
export const useScrollAnimation = <T extends HTMLElement>(
  animation: gsap.TweenVars,
  scrollTriggerOptions: ScrollTrigger.Vars = {},
  deps: React.DependencyList = []
) => {
  const elementRef = useRef<T>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    const tween = gsap.from(elementRef.current, {
      ...animation,
      scrollTrigger: {
        trigger: elementRef.current,
        start: 'top 80%',
        end: 'bottom 20%',
        toggleActions: 'play none none reverse',
        ...scrollTriggerOptions,
      },
    });

    return () => {
      tween.kill();
      ScrollTrigger.getAll().forEach(st => st.kill());
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return elementRef;
};

/**
 * Parallax effect hook
 */
export const useParallax = <T extends HTMLElement>(
  speed: number = 0.5,
  deps: React.DependencyList = []
) => {
  const elementRef = useRef<T>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    const tween = gsap.to(elementRef.current, {
      yPercent: speed * 100,
      ease: 'none',
      scrollTrigger: {
        trigger: elementRef.current,
        start: 'top bottom',
        end: 'bottom top',
        scrub: true,
      },
    });

    return () => {
      tween.kill();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return elementRef;
};

/**
 * 3D Card tilt effect
 */
export const useCardTilt = <T extends HTMLElement>(
  intensity: number = 10,
  deps: React.DependencyList = []
) => {
  const elementRef = useRef<T>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    const element = elementRef.current;

    const handleMouseMove = (e: MouseEvent) => {
      const rect = element.getBoundingClientRect();
      const x = e.clientX - rect.left - rect.width / 2;
      const y = e.clientY - rect.top - rect.height / 2;

      gsap.to(element, {
        rotateX: (-y / rect.height) * intensity,
        rotateY: (x / rect.width) * intensity,
        transformPerspective: 1000,
        duration: 0.5,
        ease: 'power2.out',
      });
    };

    const handleMouseLeave = () => {
      gsap.to(element, {
        rotateX: 0,
        rotateY: 0,
        duration: 0.5,
        ease: 'power2.out',
      });
    };

    element.addEventListener('mousemove', handleMouseMove);
    element.addEventListener('mouseleave', handleMouseLeave);

    return () => {
      element.removeEventListener('mousemove', handleMouseMove);
      element.removeEventListener('mouseleave', handleMouseLeave);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return elementRef;
};

/**
 * Magnetic button effect
 */
export const useMagneticEffect = <T extends HTMLElement>(
  strength: number = 0.3,
  deps: React.DependencyList = []
) => {
  const elementRef = useRef<T>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    const element = elementRef.current;

    const handleMouseMove = (e: MouseEvent) => {
      const rect = element.getBoundingClientRect();
      const centerX = rect.left + rect.width / 2;
      const centerY = rect.top + rect.height / 2;

      const deltaX = e.clientX - centerX;
      const deltaY = e.clientY - centerY;

      gsap.to(element, {
        x: deltaX * strength,
        y: deltaY * strength,
        duration: 0.3,
        ease: 'power2.out',
      });
    };

    const handleMouseLeave = () => {
      gsap.to(element, {
        x: 0,
        y: 0,
        duration: 0.5,
        ease: 'elastic.out(1, 0.3)',
      });
    };

    element.addEventListener('mousemove', handleMouseMove);
    element.addEventListener('mouseleave', handleMouseLeave);

    return () => {
      element.removeEventListener('mousemove', handleMouseMove);
      element.removeEventListener('mouseleave', handleMouseLeave);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return elementRef;
};

/**
 * Text reveal animation (split text)
 */
export const useTextReveal = <T extends HTMLElement>(
  deps: React.DependencyList = []
) => {
  const elementRef = useRef<T>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    const element = elementRef.current;
    const text = element.textContent || '';
    element.innerHTML = '';

    // Split text into characters
    text.split('').forEach((char) => {
      const span = document.createElement('span');
      span.textContent = char === ' ' ? '\u00A0' : char;
      span.style.display = 'inline-block';
      element.appendChild(span);
    });

    const chars = element.querySelectorAll('span');

    gsap.from(chars, {
      y: 100,
      opacity: 0,
      rotateX: -90,
      stagger: 0.02,
      duration: 0.8,
      ease: 'back.out(1.7)',
      scrollTrigger: {
        trigger: element,
        start: 'top 80%',
        toggleActions: 'play none none reverse',
      },
    });

    return () => {
      ScrollTrigger.getAll().forEach(st => st.kill());
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return elementRef;
};

/**
 * Counter animation
 */
export const useCounterAnimation = (
  endValue: number,
  duration: number = 2,
  startValue: number = 0
) => {
  const elementRef = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    if (!elementRef.current) return;

    const element = elementRef.current;
    const counter = { value: startValue };

    gsap.to(counter, {
      value: endValue,
      duration,
      ease: 'power2.out',
      onUpdate: () => {
        element.textContent = Math.round(counter.value).toLocaleString();
      },
      scrollTrigger: {
        trigger: element,
        start: 'top 80%',
        toggleActions: 'play none none reverse',
      },
    });

    return () => {
      ScrollTrigger.getAll().forEach(st => st.kill());
    };
  }, [endValue, duration, startValue]);

  return elementRef;
};

// ============================================
// ANIMATION UTILITIES
// ============================================

/**
 * Fade in animation
 */
export const fadeIn = (
  element: HTMLElement | string,
  options: gsap.TweenVars = {}
): gsap.core.Tween => {
  return gsap.from(element, {
    opacity: 0,
    duration: 0.6,
    ease: 'power2.out',
    ...options,
  });
};

/**
 * Slide up animation
 */
export const slideUp = (
  element: HTMLElement | string,
  options: gsap.TweenVars = {}
): gsap.core.Tween => {
  return gsap.from(element, {
    y: 50,
    opacity: 0,
    duration: 0.8,
    ease: 'power3.out',
    ...options,
  });
};

/**
 * Scale in animation
 */
export const scaleIn = (
  element: HTMLElement | string,
  options: gsap.TweenVars = {}
): gsap.core.Tween => {
  return gsap.from(element, {
    scale: 0.8,
    opacity: 0,
    duration: 0.6,
    ease: 'back.out(1.7)',
    ...options,
  });
};

/**
 * Stagger animation for children
 */
export const staggerChildren = (
  parent: HTMLElement | string,
  childSelector: string,
  options: gsap.TweenVars = {}
): gsap.core.Tween => {
  const children = typeof parent === 'string'
    ? document.querySelectorAll(`${parent} ${childSelector}`)
    : parent.querySelectorAll(childSelector);

  return gsap.from(children, {
    y: 30,
    opacity: 0,
    duration: 0.6,
    stagger: 0.1,
    ease: 'power2.out',
    ...options,
  });
};

/**
 * Create a timeline for complex animations
 */
export const createTimeline = (options?: gsap.TimelineVars): gsap.core.Timeline => {
  return gsap.timeline(options);
};

/**
 * Page transition animation
 */
export const pageTransition = {
  initial: { opacity: 0, y: 20 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -20 },
  transition: { duration: 0.4, ease: 'easeInOut' },
};

/**
 * Card hover animation preset
 */
export const cardHoverAnimation = (element: HTMLElement) => {
  return {
    enter: () => {
      gsap.to(element, {
        y: -8,
        scale: 1.02,
        duration: 0.3,
        ease: 'power2.out',
      });
    },
    leave: () => {
      gsap.to(element, {
        y: 0,
        scale: 1,
        duration: 0.3,
        ease: 'power2.out',
      });
    },
  };
};

/**
 * Glow pulse animation
 */
export const glowPulse = (
  element: HTMLElement | string,
  color: string = '#00ffff'
): gsap.core.Tween => {
  return gsap.to(element, {
    boxShadow: `0 0 30px ${color}`,
    duration: 1,
    repeat: -1,
    yoyo: true,
    ease: 'power1.inOut',
  });
};

/**
 * Infinite scroll/marquee animation
 */
export const marquee = (
  element: HTMLElement | string,
  direction: 'left' | 'right' = 'left',
  duration: number = 20
): gsap.core.Tween => {
  const xValue = direction === 'left' ? '-100%' : '100%';

  return gsap.to(element, {
    xPercent: direction === 'left' ? -100 : 100,
    duration,
    repeat: -1,
    ease: 'none',
  });
};

// Export GSAP for direct use
export { gsap, ScrollTrigger };
