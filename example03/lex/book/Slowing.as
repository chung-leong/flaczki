package lex.book 
{
	import flash.display.*;
	import flash.events.*;
	import lex.geom.V2;
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class Slowing
	{
		private var mc:MovieClip;
		private var finV2:V2;
		
		public var curV2:V2;
		public var doneTr:Boolean;
		public var callBack:Function;
		public var speed:Number = 4;
		private var setTimeFinTr:Boolean = false;
		private var timeFin:V2
		public function Slowing(speed:Number) 
		{
			this.speed = speed;
			mc = new MovieClip();
			finV2 = new V2();
			curV2 = new V2();
			timeFin = new V2();
		}

		private function enterFrameHandler(e:Event):void {
			var tempFin:V2;
			if (setTimeFinTr == true) {
				tempFin = timeFin.clone();
				tempFin.minus(curV2);
				tempFin.normalize();
				if (curV2.distance(timeFin) > speed) {
					tempFin.scale(speed);
					tempFin.plus(curV2);
					curV2 = tempFin.clone();
				}else{
					setTimeFinTr = false;
					curV2 = timeFin.clone();
				}
			}else{
				tempFin = finV2.clone();
				tempFin.minus(curV2);
				tempFin.normalize();
				if (curV2.distance(finV2) > speed) {
					tempFin.scale(speed);
					tempFin.plus(curV2);
					curV2 = tempFin.clone();
				}else{
					doneTr = true;
					curV2 = finV2.clone();
					mc.removeEventListener(Event.ENTER_FRAME, enterFrameHandler);
				}
			}
			if (callBack != null) callBack();
			
		}

		public function setCoord(x:Number,y:Number):void {
			doneTr = false;
			setTimeFinTr = false;
			mc.removeEventListener(Event.ENTER_FRAME, enterFrameHandler);
			finV2.x = x;
			finV2.y = y;
			if (finV2.distance(curV2) <= speed) {
				curV2.copy(finV2);
				doneTr = true;
				if (callBack != null) callBack();
			}else{
				mc.addEventListener(Event.ENTER_FRAME, enterFrameHandler);
			}
			
		}

		public function setTimeFin(x:Number, y:Number):void {
			setTimeFinTr = true;
			timeFin.x = x;
			timeFin.y = y;
		}

		public function setCurrent(x:Number, y:Number):void {
			curV2.x = x;
			curV2.y = y;
		}

	}

}