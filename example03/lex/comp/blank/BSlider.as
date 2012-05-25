package lex.comp.blank 
{
	import flash.display.*;
	import flash.events.*;
	import flash.geom.*;
	
	/**
	 * ...
	 * @author Alex Lexcuk http://www.murmadillo.tut.su
	 */
	public class BSlider extends Sprite
	{
		public var debug:Boolean = true;
		public var bColor:uint = 0xFFFFFF;
		public var lColor:uint = 0x000000;
		public var gColor:uint = 0xFF8040;
		
		
		private var gripImpact:Point = new Point();
		private var axis:String = 'x';
		private var longSize:int = 200;
		private var smallSize:int = 20;
		/*0..1*/
		private var _contentSize:Number = 0.05;
		private var react:Number = 0.01;
		private var bRect:Rectangle = new Rectangle(0, 0, longSize, smallSize);
		private var gripRect:Rectangle = new Rectangle(0, 0, longSize*_contentSize, smallSize);
		private var action:String = '';
		private var rectGrip:Boolean;

		public function BSlider() 
		{
			autoRectContentSize();
			draw();
			_contentSize = 0.5;
			direction = 'y';
			position = 0.9;
			addEventListener(MouseEvent.MOUSE_DOWN, mouseDownHandler);
		}

		/*0..1*/
		public function set contentSize(s:Number):void {
			if (s < 0.1) s = 0.1
			if (s > 1) s = 1;
			_contentSize = s;
			correctionRects();
			draw();
		}

		/*0..1*/
		public function set reaction(r:Number):void {
			react = r;
		}

		public function set modifyGripToRect(b:Boolean):void {
			rectGrip = b;
			if (rectGrip) autoRectContentSize();
		}

		private function mouseDownHandler(e:MouseEvent):void {
			var p:Point = new Point(mouseX, mouseY);
			if (gripRect.containsPoint(p)) {
				gripImpact.x = p.x - gripRect.x
				gripImpact.y = p.y - gripRect.y;
				action = 'gripMove';
			} else {
				gripImpact.x = gripImpact.y = 0;
				action = 'animationMove';
			}
			stage.addEventListener(MouseEvent.MOUSE_UP, stageMouseUpHandler);
			if (!this.hasEventListener(Event.ENTER_FRAME)) addEventListener(Event.ENTER_FRAME, enterFrameHandler);
		}

		private function autoRectContentSize():void {
			_contentSize = smallSize / longSize;
			correctionRects();
			draw();
		}

		public function setSize(long:Number, small:Number):void {
			longSize = long;
			smallSize = small;
			if (rectGrip) autoRectContentSize();
			correctionRects();
			draw();
		}

		public function set direction(s:String):void {
			var p:Number = position;
			if (s == "x") axis = 'x';
				else axis = 'y';
			correctionRects();
			position = p;
		}

		private function correctionRects():void {
			if (axis == 'x') {
				bRect.width = longSize;
				bRect.height = smallSize;
				gripRect.width = longSize*_contentSize;
				gripRect.height = smallSize;
				gripRect.y = 0;
			}else {
				gripRect.width = smallSize;
				gripRect.height = longSize * _contentSize;
				bRect.width = smallSize;
				bRect.height = longSize;
				gripRect.x = 0;
			}
			
		}

		private function stageMouseUpHandler(e:MouseEvent):void {
			stage.removeEventListener(MouseEvent.MOUSE_UP, stageMouseUpHandler);
			removeEventListener(Event.ENTER_FRAME, enterFrameHandler);
		}

		private function enterFrameHandler(e:Event):void {
			var p:Point = new Point(mouseX, mouseY);
			var paxis:Number = p[axis];
			var v:Number;
			var wh:String; if (axis == 'x') wh = 'width'; else wh = 'height';
			correctionRects();
			v = paxis - gripRect[axis] - gripRect[wh] / 2;
			v /= longSize * react;//насколько его можно передвинуть впринципе
			if (Math.abs(v) > 1) {
				if (v < 0) v = -1; else v = 1;
			}
			if (action != 'gripMove') 
				p[axis] = gripRect[axis] + longSize * react * v;
			gripMoveToPoint(p);
			draw();
			dispatchEvent(new Event(Event.CHANGE));
		}

		public function gripMoveToPoint(p:Point):void {
			var xy:String = axis;
			var wh:String; if (xy == 'x') wh = 'width'; else wh = 'height';
			p.x -= gripImpact.x;
			p.y -= gripImpact.y;
			gripRect[xy] = p[xy];
			if (gripRect[xy] + gripRect[wh] > bRect[wh]) gripRect[xy] = bRect[wh] - gripRect[wh];
			if (gripRect[xy] < 0) gripRect[xy] = 0;
		}

		private function draw():void {
			if (!debug) {
				graphics.clear();
				graphics.beginFill(bColor, 0);
				graphics.drawRect(bRect.x, bRect.y, bRect.width, bRect.height);
			}
			else {//если дебуг
				graphics.clear();
				graphics.lineStyle(1, lColor);
				graphics.beginFill(bColor);
				graphics.drawRect(bRect.x, bRect.y, bRect.width, bRect.height);
				graphics.endFill();
				graphics.beginFill(gColor);
				graphics.drawRect(gripRect.x, gripRect.y, gripRect.width, gripRect.height);
				
				graphics.drawCircle(startPos.x, startPos.y, 2);
				graphics.drawCircle(endPos.x, endPos.y, 2);
				graphics.drawCircle(gripPos.x, gripPos.y, 2);
			}
			
		}

		/*0..1*/
		public function set position(pos:Number):void {
			var len:Number = Point.distance(startPos, endPos);
			var grPos:Number = len  * pos;
			if (axis == 'x') gripRect.x = grPos; else gripRect.y = grPos;
			draw();
		}


		public function get position():Number {
			var len:Number = Point.distance(startPos, endPos);
			var grLen:Number = Point.distance(startPos, gripPos);
			var pr:Number = grLen / len;
			return pr;
		}

		private function get startPos():Point {
			var p:Point = new Point(longSize * _contentSize / 2, smallSize / 2);
			if (axis == 'x') p = new Point(p.x, p.y);
			else p = new Point(p.y, p.x);
			return p;
		}

		private function get endPos():Point {
			var p:Point = new Point(longSize - (longSize * _contentSize / 2), smallSize / 2);
			if (axis == 'x') p = new Point(p.x, p.y);
			else p = new Point(p.y, p.x);
			return p;
		}

		private function get gripPos():Point {
			var p:Point;
			if (axis == 'x') p = new Point(gripRect.x+(longSize * _contentSize / 2), smallSize / 2);
			else p = new Point(smallSize / 2, gripRect.y+(longSize * _contentSize / 2));
			return p;
		}

	}

}