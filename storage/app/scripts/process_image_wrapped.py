#!/usr/bin/env python3
"""
Script YOLO DEBUG para diagnosticar problemas de detección
"""

import cv2
import numpy as np
import sys
import json
import os
import argparse
import traceback
import warnings

# Suprimir mensajes de Ultralytics
import logging
logging.getLogger('ultralytics').setLevel(logging.ERROR)
warnings.filterwarnings('ignore', category=UserWarning, module='ultralytics')
os.environ['ULTRALYTICS_SETTINGS'] = 'false'

from ultralytics import YOLO

def save_debug_image(img, filename, output_dir):
    """Guarda imagen de debug"""
    debug_path = os.path.join(output_dir, filename)
    cv2.imwrite(debug_path, img)
    print(f"🐛 DEBUG: Imagen guardada en {debug_path}", file=sys.stderr)

def process_image_with_debug(input_path, output_path, model_path, filas=24, columnas=6, confidence=0.5):
    """Función principal con DEBUG completo"""
    try:
        debug_dir = os.path.dirname(output_path) + "/debug"
        os.makedirs(debug_dir, exist_ok=True)

        print(f"🚀 INICIANDO PROCESAMIENTO YOLO DEBUG", file=sys.stderr)
        print(f"📂 Input: {input_path}", file=sys.stderr)
        print(f"📂 Output: {output_path}", file=sys.stderr)
        print(f"📂 Debug dir: {debug_dir}", file=sys.stderr)
        print(f"🤖 Modelo: {model_path}", file=sys.stderr)

        # Cargar modelo YOLO
        if not os.path.exists(model_path):
            raise Exception(f"Modelo no encontrado: {model_path}")

        model = YOLO(model_path, verbose=False)
        print(f"✅ Modelo YOLO cargado", file=sys.stderr)

        # Cargar imagen
        img = cv2.imread(input_path)
        if img is None:
            raise Exception(f"No se pudo cargar la imagen: {input_path}")

        original_shape = img.shape
        print(f"📐 Imagen original: {original_shape[1]}x{original_shape[0]}", file=sys.stderr)

        # 🐛 DEBUG: Guardar imagen original
        save_debug_image(img, "01_original.jpg", debug_dir)

        # Rotación automática si es horizontal
        h, w = img.shape[:2]
        rotated = False
        if w > h:
            img = cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
            rotated = True
            print("🔄 Imagen rotada a vertical", file=sys.stderr)
            # 🐛 DEBUG: Guardar imagen rotada
            save_debug_image(img, "02_rotated.jpg", debug_dir)

        # 🐛 DETECCIÓN YOLO CON DEBUG
        print("🔍 Ejecutando detección YOLO...", file=sys.stderr)
        results = model.predict(
            source=img,
            conf=confidence,
            save=False,
            verbose=False,
            show=False
        )

        if not results or len(results) == 0:
            raise Exception("No se obtuvieron resultados de YOLO")

        # 🐛 DEBUG: Analizar resultados detalladamente
        result = results[0]
        print(f"🔍 Resultados YOLO:", file=sys.stderr)
        print(f"   - Boxes: {result.boxes is not None and len(result.boxes) if result.boxes is not None else 0}", file=sys.stderr)
        print(f"   - Masks: {result.masks is not None and len(result.masks.data) if result.masks is not None else 0}", file=sys.stderr)

        if result.boxes is not None and len(result.boxes) > 0:
            print(f"   - Confidence scores: {result.boxes.conf.tolist()}", file=sys.stderr)
            print(f"   - Bounding boxes: {result.boxes.xyxy.tolist()}", file=sys.stderr)

        # Verificar máscaras
        masks = result.masks
        if masks is None or masks.data.shape[0] == 0:
            raise Exception("No se detectó ninguna máscara")

        print(f"✅ Detectadas {len(masks.data)} máscara(s)", file=sys.stderr)

        # 🐛 DEBUG: Analizar todas las máscaras
        for i, mask_tensor in enumerate(masks.data):
            mask = mask_tensor.cpu().numpy()
            mask_uint8 = (mask * 255).astype(np.uint8)
            save_debug_image(mask_uint8, f"03_mask_{i}.jpg", debug_dir)

            # Estadísticas de la máscara
            mask_area = np.sum(mask > 0.5)
            mask_percentage = (mask_area / mask.size) * 100
            print(f"   - Máscara {i}: área={mask_area} píxeles ({mask_percentage:.1f}%)", file=sys.stderr)

        # Usar la primera máscara (mayor confianza)
        mask = masks.data[0].cpu().numpy()
        mask_uint8 = (mask * 255).astype(np.uint8)

        # Obtener confianza
        confidence_score = 0.0
        if result.boxes is not None and len(result.boxes.conf) > 0:
            confidence_score = float(result.boxes.conf[0])
        print(f"📊 Confianza de detección: {confidence_score:.3f}", file=sys.stderr)

        # 🐛 DEBUG: Resize de máscara a tamaño de imagen
        img_h, img_w = img.shape[:2]
        mask_resized = cv2.resize(mask_uint8, (img_w, img_h))
        save_debug_image(mask_resized, "04_mask_resized.jpg", debug_dir)

        # 🐛 DEBUG: Overlay de máscara sobre imagen original
        overlay = img.copy()
        overlay[:, :, 2] = np.where(mask_resized > 127, 255, overlay[:, :, 2])  # Canal rojo
        save_debug_image(overlay, "05_mask_overlay.jpg", debug_dir)

        # 🐛 EXTRACCIÓN DE CONTORNO CON DEBUG
        print("📐 Extrayendo contorno del panel...", file=sys.stderr)

        # Encontrar contornos
        contours, _ = cv2.findContours(mask_resized, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        print(f"🔍 Encontrados {len(contours)} contornos", file=sys.stderr)

        if not contours:
            raise Exception("No se encontraron contornos en la máscara")

        # 🐛 DEBUG: Dibujar todos los contornos
        contour_img = img.copy()
        for i, contour in enumerate(contours):
            area = cv2.contourArea(contour)
            color = (0, 255, 0) if i == 0 else (255, 0, 0)  # Verde para el mayor, azul para otros
            cv2.drawContours(contour_img, [contour], -1, color, 3)
            print(f"   - Contorno {i}: área={area:.0f} píxeles", file=sys.stderr)
        save_debug_image(contour_img, "06_all_contours.jpg", debug_dir)

        # Seleccionar contorno más grande
        largest_contour = max(contours, key=cv2.contourArea)
        area = cv2.contourArea(largest_contour)
        print(f"📏 Contorno mayor: {area:.0f} píxeles", file=sys.stderr)

        if area < 1000:
            raise Exception(f"Contorno muy pequeño: {area:.0f} píxeles")

        # 🐛 DEBUG: Aproximación de polígono con diferentes epsilon
        epsilons = [0.01, 0.02, 0.03, 0.05]
        best_approx = None

        for eps in epsilons:
            epsilon = eps * cv2.arcLength(largest_contour, True)
            approx = cv2.approxPolyDP(largest_contour, epsilon, True)
            print(f"   - Epsilon {eps}: {len(approx)} puntos", file=sys.stderr)

            # Dibujar aproximación
            approx_img = img.copy()
            cv2.drawContours(approx_img, [largest_contour], -1, (255, 0, 0), 2)  # Azul: contorno original
            if len(approx) >= 4:
                cv2.drawContours(approx_img, [approx], -1, (0, 255, 0), 3)  # Verde: aproximación
                for j, point in enumerate(approx):
                    cv2.circle(approx_img, tuple(point[0]), 10, (0, 0, 255), -1)  # Rojo: puntos
                    cv2.putText(approx_img, str(j), tuple(point[0] + [15, 15]), cv2.FONT_HERSHEY_SIMPLEX, 1, (255, 255, 255), 2)
            save_debug_image(approx_img, f"07_approx_eps{eps:.2f}.jpg", debug_dir)

            if len(approx) == 4 and best_approx is None:
                best_approx = approx

        # Usar mejor aproximación o rectángulo mínimo
        if best_approx is not None:
            panel_points = best_approx[:, 0].astype(np.float32)
            print(f"✅ Usando aproximación de {len(best_approx)} puntos", file=sys.stderr)
        else:
            print("⚠️ No se encontró cuadrilátero, usando rectángulo mínimo", file=sys.stderr)
            rect = cv2.minAreaRect(largest_contour)
            box = cv2.boxPoints(rect)
            panel_points = box.astype(np.float32)

            # 🐛 DEBUG: Rectángulo mínimo
            rect_img = img.copy()
            cv2.drawContours(rect_img, [np.int32(box)], -1, (0, 255, 255), 3)
            save_debug_image(rect_img, "08_min_rect.jpg", debug_dir)

        print(f"📊 Puntos del panel: {panel_points}", file=sys.stderr)

        # 🐛 TRANSFORMACIÓN DE PERSPECTIVA CON DEBUG
        print("🔄 Aplicando transformación de perspectiva...", file=sys.stderr)

        # Ordenar puntos
        def order_points(pts):
            rect = np.zeros((4, 2), dtype="float32")
            s = pts.sum(axis=1)
            rect[0] = pts[np.argmin(s)]       # top-left
            rect[2] = pts[np.argmax(s)]       # bottom-right
            diff = np.diff(pts, axis=1)
            rect[1] = pts[np.argmin(diff)]    # top-right
            rect[3] = pts[np.argmax(diff)]    # bottom-left
            return rect

        ordered_points = order_points(panel_points)
        print(f"📊 Puntos ordenados: {ordered_points}", file=sys.stderr)

        # Calcular dimensiones
        width_top = np.linalg.norm(ordered_points[1] - ordered_points[0])
        width_bottom = np.linalg.norm(ordered_points[2] - ordered_points[3])
        width = int(max(width_top, width_bottom))

        height_left = np.linalg.norm(ordered_points[3] - ordered_points[0])
        height_right = np.linalg.norm(ordered_points[2] - ordered_points[1])
        height = int(max(height_left, height_right))

        print(f"📏 Dimensiones calculadas: {width}x{height}", file=sys.stderr)

        if width <= 0 or height <= 0 or width < 50 or height < 50:
            raise Exception(f"Dimensiones inválidas: {width}x{height}")

        # Puntos de destino
        dst = np.array([
            [0, 0],
            [width - 1, 0],
            [width - 1, height - 1],
            [0, height - 1]
        ], dtype="float32")

        # 🐛 DEBUG: Mostrar puntos de transformación
        transform_img = img.copy()
        colors = [(255, 0, 0), (0, 255, 0), (0, 0, 255), (255, 255, 0)]
        for i, point in enumerate(ordered_points):
            cv2.circle(transform_img, tuple(point.astype(int)), 15, colors[i], -1)
            cv2.putText(transform_img, str(i), tuple(point.astype(int) + [20, 20]), cv2.FONT_HERSHEY_SIMPLEX, 1, (255, 255, 255), 2)
        save_debug_image(transform_img, "09_transform_points.jpg", debug_dir)

        # Aplicar transformación
        M = cv2.getPerspectiveTransform(ordered_points, dst)
        warped = cv2.warpPerspective(img, M, (width, height))

        # 🐛 DEBUG: Guardar resultado de transformación
        save_debug_image(warped, "10_warped.jpg", debug_dir)

        # Verificar resultado
        if warped.size == 0:
            raise Exception("Resultado de transformación vacío")

        # Estadísticas del resultado
        gray_warped = cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY)
        mean_brightness = np.mean(gray_warped)
        std_brightness = np.std(gray_warped)
        non_zero = np.count_nonzero(gray_warped > 10)

        print(f"📊 Estadísticas resultado:", file=sys.stderr)
        print(f"   - Brillo medio: {mean_brightness:.2f}", file=sys.stderr)
        print(f"   - Desviación: {std_brightness:.2f}", file=sys.stderr)
        print(f"   - Píxeles no-negros: {non_zero}/{gray_warped.size} ({(non_zero/gray_warped.size)*100:.1f}%)", file=sys.stderr)

        # Si el resultado es muy oscuro, intentar normalizar
        if mean_brightness < 30:
            print("⚠️ Resultado muy oscuro, normalizando...", file=sys.stderr)
            warped_normalized = cv2.normalize(warped, None, 0, 255, cv2.NORM_MINMAX)
            save_debug_image(warped_normalized, "11_normalized.jpg", debug_dir)
            warped = warped_normalized

        # Aplicar mejoras básicas
        enhanced = warped.copy()
        try:
            hsv = cv2.cvtColor(enhanced, cv2.COLOR_BGR2HSV)
            hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 20)
            clahe = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8))
            hsv[:, :, 2] = clahe.apply(hsv[:, :, 2])
            enhanced = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)
            save_debug_image(enhanced, "12_enhanced.jpg", debug_dir)
        except:
            print("⚠️ Error en mejoras, usando resultado sin mejorar", file=sys.stderr)

        # Guardar resultado final
        success = cv2.imwrite(output_path, enhanced)
        if not success:
            raise Exception(f"Error guardando imagen: {output_path}")

        print(f"💾 Imagen guardada: {output_path}", file=sys.stderr)

        # Calcular métricas finales
        gray_final = cv2.cvtColor(enhanced, cv2.COLOR_BGR2GRAY)
        non_black = np.count_nonzero(gray_final > 10)
        integridad = round((non_black / gray_final.size) * 100, 2)

        hsv_final = cv2.cvtColor(enhanced, cv2.COLOR_BGR2HSV)
        luminosidad = round(np.mean(hsv_final[:, :, 2]), 2)
        uniformidad = round(np.std(gray_final), 2)

        # Calcular reducción de tamaño
        original_pixels = original_shape[0] * original_shape[1]
        final_pixels = enhanced.shape[0] * enhanced.shape[1]
        reduction = ((original_pixels - final_pixels) / original_pixels) * 100

        # Resultado exitoso
        result = {
            "success": True,
            "method": "yolo_segmentation_debug",
            "model_path": model_path,
            "confidence": float(confidence_score),
            "integridad": float(integridad),
            "luminosidad": float(luminosidad),
            "uniformidad": float(uniformidad),
            "filas": int(filas),
            "columnas": int(columnas),
            "imagen_rotada": rotated,
            "reduccion_tamaño": f"{reduction:.1f}%",
            "dimensiones_finales": f"{enhanced.shape[1]}x{enhanced.shape[0]}",
            "algorithm_version": "yolo_v8_segmentation_debug",
            "procesamiento_exitoso": True,
            "tipo_imagen": "YOLO_Enhanced_Debug",
            "debug_info": {
                "mask_area_percentage": float((np.sum(mask > 0.5) / mask.size) * 100),
                "contour_area": float(area),
                "transform_width": int(width),
                "transform_height": int(height),
                "mean_brightness": float(mean_brightness),
                "debug_dir": debug_dir
            }
        }

        print("🎉 PROCESAMIENTO YOLO DEBUG COMPLETADO", file=sys.stderr)
        print(json.dumps(result, ensure_ascii=True))

    except Exception as e:
        error_msg = str(e)
        print(f"💀 ERROR EN PROCESAMIENTO YOLO DEBUG: {error_msg}", file=sys.stderr)
        print(f"💀 TRACEBACK: {traceback.format_exc()}", file=sys.stderr)

        result = {
            "success": False,
            "error": error_msg,
            "method": "yolo_segmentation_debug_failed",
            "traceback": traceback.format_exc()
        }

        print(json.dumps(result, ensure_ascii=True))
        sys.exit(1)

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Procesamiento YOLO con DEBUG completo')
    parser.add_argument('input_path', help='Ruta de la imagen de entrada')
    parser.add_argument('output_path', help='Ruta donde guardar la imagen procesada')
    parser.add_argument('model_path', help='Ruta del modelo YOLO (.pt)')
    parser.add_argument('--filas', type=int, default=24, help='Número de filas del panel')
    parser.add_argument('--columnas', type=int, default=6, help='Número de columnas del panel')
    parser.add_argument('--confidence', type=float, default=0.5, help='Umbral de confianza YOLO')

    args = parser.parse_args()

    process_image_with_debug(
        args.input_path,
        args.output_path,
        args.model_path,
        args.filas,
        args.columnas,
        args.confidence
    )
